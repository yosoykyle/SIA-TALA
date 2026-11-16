<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Models\User;
use App\Support\SafeUserNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use TypeError;

class SafeUserNotificationTest extends TestCase
{
    public function test_preserves_domain_and_validation_messages(): void
    {
        $validation = ValidationException::withMessages(['field' => ['Exact validation failure.']]);
        $authorization = new AuthorizationException('You are not authorized for this action.');
        $domainRuntime = new RuntimeException('Selected fee plan is not active.');
        $lifecycleViolation = new StudentLifecycleRuleViolation('Student has an active course drop review.');

        $this->assertSame('Exact validation failure.', SafeUserNotification::message($validation));
        $this->assertSame('You are not authorized for this action.', SafeUserNotification::message($authorization));
        $this->assertSame('Selected fee plan is not active.', SafeUserNotification::message($domainRuntime));
        $this->assertSame('Student has an active course drop review.', SafeUserNotification::message($lifecycleViolation));
    }

    public function test_masks_internal_database_type_and_path_exceptions_with_calm_fallback(): void
    {
        $fallback = 'Your request could not be completed. Nothing was changed.';
        $queryException = new QueryException('mysql', 'select * from users', [], new PDOException('SQLSTATE[42S02]: Base table not found'));
        $typeError = new TypeError('Argument #1 ($user) must be of type App\\Models\\User, null given');
        $leakedPathRuntime = new RuntimeException('Failed reading /var/www/app/storage/private/file.php');
        $leakedDsnRuntime = new RuntimeException('Sensitive DB connection string: mysql://secret@internal-cluster:3306');
        $leakedRedisDsnRuntime = new RuntimeException('Redis failure at rediss://default:token@10.0.0.8:6379');
        $leakedNamespaceRuntime = new RuntimeException('App\\Actions\\Finance\\SubmitPaymentEvidence failed: Connection refused');

        $this->assertSame($fallback, SafeUserNotification::message($queryException, $fallback));
        $this->assertSame($fallback, SafeUserNotification::message($typeError, $fallback));
        $this->assertSame($fallback, SafeUserNotification::message($leakedPathRuntime, $fallback));
        $this->assertSame($fallback, SafeUserNotification::message($leakedDsnRuntime, $fallback));
        $this->assertSame($fallback, SafeUserNotification::message($leakedRedisDsnRuntime, $fallback));
        $this->assertSame($fallback, SafeUserNotification::message($leakedNamespaceRuntime, $fallback));
    }

    public function test_formats_user_account_status_labels_and_semantic_colors(): void
    {
        $this->assertSame('Invitation pending', User::statusLabel(User::StatusInvitationPending));
        $this->assertSame('warning', User::statusColor(User::StatusInvitationPending));

        $this->assertSame('Verification required', User::statusLabel(User::StatusVerificationRequired));
        $this->assertSame('warning', User::statusColor(User::StatusVerificationRequired));

        $this->assertSame('Active', User::statusLabel(User::StatusActive));
        $this->assertSame('success', User::statusColor(User::StatusActive));

        $this->assertSame('Disabled', User::statusLabel(User::StatusDisabled));
        $this->assertSame('danger', User::statusColor(User::StatusDisabled));
    }
}
