<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

use DigitalLibrary\Audit\AuditLogger;
use DigitalLibrary\Auth\AuthService;
use DigitalLibrary\Auth\UserAdministration;
use DigitalLibrary\Auth\UserRepository;
use DigitalLibrary\Catalogue\BookService;
use DigitalLibrary\Catalogue\CatalogueSearch;
use DigitalLibrary\Catalogue\Repository\AuthorRepository;
use DigitalLibrary\Catalogue\Repository\CategoryRepository;
use DigitalLibrary\Catalogue\Repository\CopyRepository;
use DigitalLibrary\Catalogue\Repository\CoverRepository;
use DigitalLibrary\Catalogue\Repository\EditionRepository;
use DigitalLibrary\Catalogue\Repository\LocationRepository;
use DigitalLibrary\Catalogue\Repository\PublisherRepository;
use DigitalLibrary\Catalogue\Repository\WorkRepository;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\I18n\Translator;
use DigitalLibrary\Audit\AuditLogRepository;
use DigitalLibrary\Backup\BackupCreator;
use DigitalLibrary\Backup\BackupRestorer;
use DigitalLibrary\Collections\CollectionRepository;
use DigitalLibrary\Collections\CollectionService;
use DigitalLibrary\Invitations\InvitationDelivery;
use DigitalLibrary\Invitations\InvitationRepository;
use DigitalLibrary\Invitations\InvitationService;
use DigitalLibrary\Invitations\ManualLinkDelivery;
use DigitalLibrary\Loans\LoanRepository;
use DigitalLibrary\Loans\LoanService;
use DigitalLibrary\Wishlist\WishlistRepository;
use DigitalLibrary\Wishlist\WishlistService;
use DigitalLibrary\Metadata\CoverImporter;
use DigitalLibrary\Metadata\Http\CurlHttpClient;
use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Metadata\Http\OutboundPolicy;
use DigitalLibrary\Metadata\MetadataService;
use DigitalLibrary\Metadata\Provider\GoogleBooksProvider;
use DigitalLibrary\Metadata\Provider\OpenLibraryProvider;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Csrf;
use DigitalLibrary\Security\LoginThrottle;
use DigitalLibrary\Security\PasswordHasher;
use DigitalLibrary\Security\PasswordPolicy;
use DigitalLibrary\Security\Session;
use DigitalLibrary\Upload\CoverImageProcessor;
use DigitalLibrary\View\View;
use PDO;

/**
 * Hand-wired, lazily-constructed service container. Deliberately explicit:
 * every dependency is visible here, with no reflection or magic.
 */
final class Services
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(
        public readonly Config $config,
        public readonly string $basePath,
    ) {
    }

    /**
     * Replace a service (tests, CLI).
     */
    public function set(string $id, object $service): void
    {
        $this->instances[$id] = $service;
    }

    public function pdo(): PDO
    {
        return $this->once(PDO::class, fn (): PDO => ConnectionFactory::fromConfig($this->config));
    }

    public function session(): Session
    {
        return $this->once(Session::class, fn (): Session => new Session(
            true,
            $this->config->bool('SESSION_SECURE_COOKIE', true),
            $this->config->int('SESSION_IDLE_TIMEOUT', 1800),
            $this->config->int('SESSION_ABSOLUTE_TIMEOUT', 43200),
        ));
    }

    public function csrf(): Csrf
    {
        return $this->once(Csrf::class, fn (): Csrf => new Csrf($this->session()));
    }

    public function translator(): Translator
    {
        return $this->once(Translator::class, fn (): Translator => new Translator(
            $this->basePath . '/lang',
            $this->defaultLocale(),
        ));
    }

    public function defaultLocale(): string
    {
        $locale = $this->config->get('APP_LOCALE', 'en');

        return Translator::isSupported($locale) ? (string) $locale : 'en';
    }

    public function view(): View
    {
        return $this->once(View::class, fn (): View => new View($this->basePath . '/templates', $this->translator()));
    }

    public function hasher(): PasswordHasher
    {
        return $this->once(PasswordHasher::class, fn (): PasswordHasher => new PasswordHasher(
            $this->config->int('ARGON2_MEMORY_KIB', 65536),
            $this->config->int('ARGON2_TIME_COST', 3),
        ));
    }

    public function passwordPolicy(): PasswordPolicy
    {
        return $this->once(PasswordPolicy::class, fn (): PasswordPolicy => new PasswordPolicy());
    }

    public function users(): UserRepository
    {
        return $this->once(UserRepository::class, fn (): UserRepository => new UserRepository($this->pdo()));
    }

    public function throttle(): LoginThrottle
    {
        return $this->once(LoginThrottle::class, fn (): LoginThrottle => new LoginThrottle(
            $this->pdo(),
            $this->config->int('LOGIN_MAX_ATTEMPTS', 5),
            $this->config->int('LOGIN_MAX_ATTEMPTS_PER_IP', 20),
            $this->config->int('LOGIN_LOCKOUT_SECONDS', 900),
        ));
    }

    /**
     * Separate limiter for invitation links: failures are keyed by client IP
     * (stored hashed in login_attempts), never by token.
     */
    public function invitationThrottle(): LoginThrottle
    {
        return $this->once('invitation-throttle', fn (): LoginThrottle => new LoginThrottle(
            $this->pdo(),
            $this->config->int('INVITATION_MAX_FAILURES', 10),
            $this->config->int('LOGIN_MAX_ATTEMPTS_PER_IP', 20),
            $this->config->int('LOGIN_LOCKOUT_SECONDS', 900),
        ));
    }

    public function maintenance(): MaintenanceMode
    {
        return $this->once(MaintenanceMode::class, fn (): MaintenanceMode => new MaintenanceMode(
            $this->config->get('MAINTENANCE_FLAG_PATH', $this->basePath . '/storage/maintenance.flag'),
        ));
    }

    public function backupDirectory(): string
    {
        return rtrim((string) $this->config->get('BACKUP_PATH', $this->basePath . '/storage/backups'), '/');
    }

    public function tempDirectory(): string
    {
        return rtrim((string) $this->config->get('TEMP_PATH', $this->basePath . '/storage/tmp'), '/');
    }

    public function coverDirectory(): string
    {
        return rtrim((string) $this->config->get('COVER_STORAGE_PATH', $this->basePath . '/storage/covers'), '/');
    }

    public function backupCreator(): BackupCreator
    {
        return new BackupCreator($this->pdo(), $this->coverDirectory(), $this->basePath . '/public');
    }

    /**
     * Restores need DDL rights, so they use the migration account against
     * the given database.
     */
    public function backupRestorer(\PDO $migrationConnection, ?string $coversDirectory = null): BackupRestorer
    {
        return new BackupRestorer($migrationConnection, rtrim($coversDirectory ?? $this->coverDirectory(), '/'), $this->tempDirectory());
    }

    public function invitations(): InvitationRepository
    {
        return $this->once(InvitationRepository::class, fn (): InvitationRepository => new InvitationRepository($this->pdo()));
    }

    public function invitationService(): InvitationService
    {
        return $this->once(InvitationService::class, fn (): InvitationService => new InvitationService(
            $this->pdo(),
            $this->invitations(),
            $this->users(),
            $this->authorizer(),
            max(1, min(90, $this->config->int('INVITATION_MAX_DAYS', 30))),
        ));
    }

    /**
     * Phase 4B: links are shown to the administrator; swap in an e-mail
     * implementation here once outbound mail is approved and configured.
     */
    public function invitationDelivery(): InvitationDelivery
    {
        return $this->once(InvitationDelivery::class, fn (): InvitationDelivery => new ManualLinkDelivery());
    }

    public function audit(): AuditLogger
    {
        return $this->once(AuditLogger::class, fn (): AuditLogger => new AuditLogger($this->pdo()));
    }

    public function auth(): AuthService
    {
        return $this->once(AuthService::class, fn (): AuthService => new AuthService(
            $this->users(),
            $this->hasher(),
            $this->throttle(),
            $this->session(),
            $this->csrf(),
            $this->audit(),
        ));
    }

    public function userAdministration(): UserAdministration
    {
        return $this->once(UserAdministration::class, fn (): UserAdministration => new UserAdministration($this->pdo(), $this->users()));
    }

    public function httpClient(): HttpClient
    {
        return $this->once(HttpClient::class, fn (): HttpClient => new CurlHttpClient(
            OutboundPolicy::defaults(),
            max(2, min(20, $this->config->int('METADATA_TIMEOUT_SECONDS', 8))),
        ));
    }

    /**
     * Providers in priority order from METADATA_PROVIDERS
     * ("open_library,google_books" by default; "none" disables lookups).
     */
    public function metadata(): MetadataService
    {
        return $this->once(MetadataService::class, function (): MetadataService {
            $names = $this->config->get('METADATA_PROVIDERS') === null
                ? ['open_library', 'google_books']
                : $this->config->list('METADATA_PROVIDERS');
            $providers = [];
            foreach (array_unique($names) as $name) {
                $providers[] = match ($name) {
                    'open_library' => new OpenLibraryProvider($this->httpClient()),
                    'google_books' => new GoogleBooksProvider($this->httpClient(), $this->config->get('GOOGLE_BOOKS_API_KEY')),
                    'none' => null,
                    default => throw new \DigitalLibrary\Core\ConfigException('Unknown metadata provider: ' . $name),
                };
            }

            return new MetadataService(
                $this->pdo(),
                array_values(array_filter($providers)),
                max(1, $this->config->int('METADATA_CACHE_DAYS', 30)),
            );
        });
    }

    public function coverImporter(): CoverImporter
    {
        return $this->once(CoverImporter::class, fn (): CoverImporter => new CoverImporter(
            $this->pdo(),
            $this->httpClient(),
            $this->covers(),
            $this->coverRecords(),
            $this->config->int('UPLOAD_MAX_BYTES', 5 * 1024 * 1024),
        ));
    }

    public function loans(): LoanRepository
    {
        return $this->once(LoanRepository::class, fn (): LoanRepository => new LoanRepository($this->pdo()));
    }

    public function loanService(): LoanService
    {
        return $this->once(LoanService::class, fn (): LoanService => new LoanService($this->pdo(), $this->loans(), $this->authorizer()));
    }

    public function wishlist(): WishlistRepository
    {
        return $this->once(WishlistRepository::class, fn (): WishlistRepository => new WishlistRepository($this->pdo()));
    }

    public function wishlistService(): WishlistService
    {
        return $this->once(WishlistService::class, fn (): WishlistService => new WishlistService($this->pdo(), $this->wishlist(), $this->authorizer()));
    }

    public function auditLog(): AuditLogRepository
    {
        return $this->once(AuditLogRepository::class, fn (): AuditLogRepository => new AuditLogRepository($this->pdo()));
    }

    public function collections(): CollectionRepository
    {
        return $this->once(CollectionRepository::class, fn (): CollectionRepository => new CollectionRepository($this->pdo()));
    }

    public function collectionService(): CollectionService
    {
        return $this->once(CollectionService::class, fn (): CollectionService => new CollectionService($this->pdo(), $this->collections(), $this->authorizer()));
    }

    public function authorizer(): Authorizer
    {
        return $this->once(Authorizer::class, fn (): Authorizer => new Authorizer());
    }

    public function covers(): CoverImageProcessor
    {
        return $this->once(CoverImageProcessor::class, fn (): CoverImageProcessor => new CoverImageProcessor(
            $this->config->get('COVER_STORAGE_PATH', $this->basePath . '/storage/covers'),
            $this->config->int('UPLOAD_MAX_BYTES', 5 * 1024 * 1024),
        ));
    }

    public function works(): WorkRepository
    {
        return $this->once(WorkRepository::class, fn (): WorkRepository => new WorkRepository($this->pdo()));
    }

    public function editions(): EditionRepository
    {
        return $this->once(EditionRepository::class, fn (): EditionRepository => new EditionRepository($this->pdo()));
    }

    public function authors(): AuthorRepository
    {
        return $this->once(AuthorRepository::class, fn (): AuthorRepository => new AuthorRepository($this->pdo()));
    }

    public function publishers(): PublisherRepository
    {
        return $this->once(PublisherRepository::class, fn (): PublisherRepository => new PublisherRepository($this->pdo()));
    }

    public function copies(): CopyRepository
    {
        return $this->once(CopyRepository::class, fn (): CopyRepository => new CopyRepository($this->pdo()));
    }

    public function coverRecords(): CoverRepository
    {
        return $this->once(CoverRepository::class, fn (): CoverRepository => new CoverRepository($this->pdo()));
    }

    public function categories(): CategoryRepository
    {
        return $this->once(CategoryRepository::class, fn (): CategoryRepository => new CategoryRepository($this->pdo()));
    }

    public function locations(): LocationRepository
    {
        return $this->once(LocationRepository::class, fn (): LocationRepository => new LocationRepository($this->pdo()));
    }

    public function books(): BookService
    {
        return $this->once(BookService::class, fn (): BookService => new BookService(
            $this->pdo(),
            $this->works(),
            $this->editions(),
            $this->authors(),
            $this->publishers(),
            $this->copies(),
        ));
    }

    public function search(): CatalogueSearch
    {
        return $this->once(CatalogueSearch::class, fn (): CatalogueSearch => new CatalogueSearch($this->pdo(), $this->categories(), $this->locations()));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param callable(): T   $factory
     *
     * @return T
     */
    private function once(string $id, callable $factory): object
    {
        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $factory();
        }
        /** @var T */
        return $this->instances[$id];
    }
}
