<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

use function in_array;
use function is_string;
use function str_starts_with;
use function strstr;

/**
 * 1.11 (REQ-RUNTIME-003 rule 6): a lock conflict on a route of the bundle answers a conflict instead of a 500.
 *
 * - Lock conflict (a deadlock, lock wait timeout or serialization failure anywhere down the chain, a
 *   {@see TransientStorageFailure}, or a lost savepoint of unknown outcome): the status of the
 *   {@see ConflictResponsePolicy} (**409** by default, 503 when the host configures it) with a {@see ConflictCode} in the
 *   `X-Conflict-Code` header:
 *   - `CONFLICT_001` with `Retry-After` when nothing of the request was committed;
 *   - `CONFLICT_002` without `Retry-After` when part of it may be: anything committed since the request started
 *     (transaction-doctrine's request mark, {@see ConflictCode::forRequest()}, when its bundle is registered with
 *     `commit_detection`; and the bundle's own mark on the {@see CommitLedger} the commit-tracking middleware feeds),
 *     a committed `ConcurrencyConflict`, or a lost savepoint with no deadlock behind it.
 * - A unique key refusing an admin save (slug, field name per form, one translation per locale; for example two admins
 *   adding the same missing translation at once): 409 with the domain code {@see self::DUPLICATE_KEY} in
 *   `X-Error-Code`, never a `CONFLICT_*` code (it is not a lock conflict). On the public form, a unique violation (the
 *   submission tables have no unique key), a lost connection or any other storage error is not mapped: a real error.
 *
 * The pages are rendered by the application's error page: the answer is an HttpException with a fixed, client-safe
 * message and the storage error as previous (for the logs; no SQL or value reaches the client).
 *
 * Only the bundle's controllers: the host's routes (and the host's calls of the request-free recorder port) keep the
 * host's error handling. Stateless apart from the shared ledger, a monotonic counter read by difference only (FrankenPHP
 * worker mode, kernel reset off).
 */
final readonly class ContactFormConflictListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** kernel.request priority: before the router (32) and the firewall (8), so no controller wrote yet. */
    public const REQUEST_PRIORITY = 2048;

    /** Request attribute holding the bundle's own commit mark. */
    public const MARK_ATTRIBUTE = '_nowo_contact_form_commit_mark';

    /** Response header carrying a domain error code (never a conflict code). */
    public const ERROR_CODE_HEADER = 'X-Error-Code';

    /** Domain code of an admin save refused by a unique key (concurrent edit, or a slug / name already used). */
    public const DUPLICATE_KEY = 'CONTACT_FORM_DUPLICATE_KEY';

    /** Message of a CONFLICT_001 answer (nothing committed). */
    public const RETRY_SAFE_MESSAGE = 'Another change is being saved at the same time. Retry in a moment.';

    /** Message of a CONFLICT_002 answer (part of the request may be committed). */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload the page before trying again.';

    /** Message of the duplicate-key answer. */
    public const DUPLICATE_KEY_MESSAGE = 'This slug, field name or translation is already used, possibly by a change saved at the same time. Reload the page and apply your change again.';

    /** Controller namespace of the bundle. */
    private const CONTROLLER_PREFIX = 'Nowo\\ContactFormBundle\\Controller\\';

    /** Admin controllers: the only bundle routes that write rows with a unique key. */
    private const ADMIN_CONTROLLERS = [
        self::CONTROLLER_PREFIX . 'ContactFormAdminController',
        self::CONTROLLER_PREFIX . 'ContactFormFieldAdminController',
        self::CONTROLLER_PREFIX . 'ContactSubmissionAdminController',
    ];

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy the conflict status and default Retry-After (null = 409, 1 s)
     * @param CommitLedger|null $ledger real commits counted by the bundle's commit-tracking middleware (null = none)
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        private ?CommitLedger $ledger = null,
        private ?RequestStack $requestStack = null,
    ) {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Takes the bundle's commit mark on the main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger && $event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::MARK_ATTRIBUTE, new CommitMark($this->ledger));
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request    = $event->getRequest();
        $controller = $this->bundleController($request);
        if ($controller === null) {
            return;
        }

        $failure = $event->getThrowable();
        if ($this->isLockConflict($failure)) {
            $code = $this->code($request, $failure);
            $event->setThrowable($this->policy->httpExceptionFor(
                $code,
                $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE,
                $failure,
                $this->hint($failure),
            ));

            return;
        }

        if (in_array($controller, self::ADMIN_CONTROLLERS, true) && $this->isUniqueViolation($failure)) {
            $event->setThrowable(new ConflictHttpException(self::DUPLICATE_KEY_MESSAGE, $failure, 0, [self::ERROR_CODE_HEADER => self::DUPLICATE_KEY]));
        }
    }

    private function isLockConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return $this->classifier->isTransient($failure) || $this->classifier->isCommitOutcomeUnknown($failure);
    }

    /**
     * CONFLICT_002 when transaction-doctrine's request mark, or the bundle's own one, saw a commit; else the failure decides.
     */
    private function code(Request $request, Throwable $failure): ConflictCode
    {
        $code = ConflictCode::forRequest($request, $failure);
        if ($code->retrySafe() && $this->ownMark($request)?->committedSince() === true) {
            return ConflictCode::Committed;
        }

        return $code;
    }

    private function ownMark(Request $request): ?CommitMark
    {
        $mark = $request->attributes->get(self::MARK_ATTRIBUTE) ?? $this->requestStack?->getMainRequest()?->attributes->get(self::MARK_ATTRIBUTE);

        return $mark instanceof CommitMark ? $mark : null;
    }

    /** The first Retry-After hint of the chain (a transient failure of a runner); null for none or zero. */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }

    private function isUniqueViolation(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    /**
     * The controller class when the request is handled by a controller of the bundle, else null.
     */
    private function bundleController(Request $request): ?string
    {
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return null;
        }

        $class = strstr($controller, '::', true);

        return $class === false ? $controller : $class;
    }
}
