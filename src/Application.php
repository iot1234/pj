<?php
declare(strict_types=1);

namespace Dormitory;

use Dormitory\Domain\AuthService;
use Dormitory\Domain\AdminUserService;
use Dormitory\Domain\BillingService;
use Dormitory\Domain\BookingService;
use Dormitory\Domain\MeterService;
use Dormitory\Domain\LineBindingService;
use Dormitory\Domain\LineWebhookService;
use Dormitory\Domain\LineOfficialAccountService;
use Dormitory\Domain\LineRoomBindingService;
use Dormitory\Domain\LineAdminRecipientService;
use Dormitory\Domain\LineNoticeService;
use Dormitory\Domain\NotificationService;
use Dormitory\Domain\PaymentService;
use Dormitory\Domain\RoomService;
use Dormitory\Domain\ResidentService;
use Dormitory\Domain\SystemSettingsService;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\RateLimiter;
use Dormitory\Security\Security;
use Dormitory\Security\SessionManager;

final class Application
{
    private Database $database;
    private SessionManager $session;
    private Security $security;
    private RateLimiter $limiter;
    private AuditLogger $audit;
    private View $view;
    private AuthService $auth;
    private AdminUserService $adminUsers;
    private ResidentService $residents;
    private RoomService $rooms;
    private BookingService $bookings;
    private MeterService $meters;
    private BillingService $billing;
    private SystemSettingsService $settings;
    private NotificationService $notifications;
    private LineBindingService $lineBindings;
    private LineWebhookService $lineWebhook;
    private LineOfficialAccountService $lineOfficialAccounts;
    private LineRoomBindingService $lineRoomBindings;
    private LineAdminRecipientService $lineAdminRecipients;
    private LineNoticeService $lineNotices;
    private PaymentService $payments;
    private ?\Dormitory\Domain\TransferInstructionService $transferInstructions = null;
    /** @var array<string,mixed>|null|false */
    private array|null|false $actorCache = false;

    public function __construct(public readonly Config $config)
    {
        $this->database = new Database($config);
        $this->session = new SessionManager($config);
        $this->security = new Security($config, $this->session);
        $this->limiter = new RateLimiter($this->database, $config);
        $this->audit = new AuditLogger($this->database, $this->security);
        $this->view = new View($config->root . '/templates');
        $this->auth = new AuthService($this);
        $this->adminUsers = new AdminUserService($this);
        $this->residents = new ResidentService($this);
        $this->rooms = new RoomService($this);
        $this->bookings = new BookingService($this);
        $this->meters = new MeterService($this);
        $this->billing = new BillingService($this);
        $this->settings = new SystemSettingsService($this);
        $this->notifications = new NotificationService($this);
        $this->lineBindings = new LineBindingService($this);
        $this->lineWebhook = new LineWebhookService($this);
        $this->lineOfficialAccounts = new LineOfficialAccountService($this);
        $this->lineRoomBindings = new LineRoomBindingService($this);
        $this->lineAdminRecipients = new LineAdminRecipientService($this);
        $this->lineNotices = new LineNoticeService($this);
        $this->payments = new PaymentService($this);
    }

    public function database(): Database { return $this->database; }
    public function session(): SessionManager { return $this->session; }
    public function security(): Security { return $this->security; }
    public function limiter(): RateLimiter { return $this->limiter; }
    public function audit(): AuditLogger { return $this->audit; }
    public function view(): View { return $this->view; }
    public function auth(): AuthService { return $this->auth; }
    public function adminUsers(): AdminUserService { return $this->adminUsers; }
    public function residents(): ResidentService { return $this->residents; }
    public function rooms(): RoomService { return $this->rooms; }
    public function bookings(): BookingService { return $this->bookings; }
    public function meters(): MeterService { return $this->meters; }
    public function billing(): BillingService { return $this->billing; }
    public function settings(): SystemSettingsService { return $this->settings; }
    public function notifications(): NotificationService { return $this->notifications; }
    public function lineBindings(): LineBindingService { return $this->lineBindings; }
    public function lineWebhook(): LineWebhookService { return $this->lineWebhook; }
    public function lineOfficialAccounts(): LineOfficialAccountService { return $this->lineOfficialAccounts; }
    public function lineRoomBindings(): LineRoomBindingService { return $this->lineRoomBindings; }
    public function lineAdminRecipients(): LineAdminRecipientService { return $this->lineAdminRecipients; }
    public function lineNotices(): LineNoticeService { return $this->lineNotices; }
    public function payments(): PaymentService { return $this->payments; }
    public function transfers(): \Dormitory\Domain\TransferInstructionService { return $this->transferInstructions ??= new \Dormitory\Domain\TransferInstructionService($this); }
    public function lineBills(): \Dormitory\Domain\LineBillService { return new \Dormitory\Domain\LineBillService($this); }

    /** @return array<string,mixed>|null */
    public function actor(bool $refresh = false): ?array
    {
        if ($refresh || $this->actorCache === false) {
            $this->actorCache = $this->auth->resolveActor();
        }
        return $this->actorCache ?: null;
    }

    public function clearActorCache(): void
    {
        $this->actorCache = false;
    }

    /** @param array<string,mixed> $options */
    public function guard(Request $request, array $options): void
    {
        $required = $options['auth'] ?? null;
        $actor = null;
        if ($required !== null) {
            $actor = $this->actor();
            if (!$actor || ($actor['type'] ?? null) !== $required) {
                throw new HttpException(401, 'Authentication required', 'UNAUTHENTICATED');
            }
        }

        $signedLineWebhook = $request->method === 'POST' && Request::isLineWebhookPath($request->path);
        if ($request->isMutation() && str_starts_with($request->path, '/api/') && !$signedLineWebhook) {
            $this->security->assertMutation($request);
        }

        if ($required === null) {
            return;
        }
        if (isset($options['role'])) {
            $roles = is_array($options['role']) ? $options['role'] : [$options['role']];
            if (!in_array($actor['role'] ?? null, $roles, true)) {
                throw new HttpException(403, 'Insufficient permission', 'FORBIDDEN');
            }
        }
        $this->session->release();
    }
}
