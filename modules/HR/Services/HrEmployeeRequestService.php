<?php

namespace Modules\HR\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;
use Modules\HR\Models\HrEmployeeServiceRequest;

class HrEmployeeRequestService
{
    public function __construct(private readonly HrAttendanceService $attendance) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(User $user, array $data): HrEmployeeServiceRequest
    {
        $employee = $this->attendance->employeeForUser($user);
        $currencyId = null;

        if (filled($data['currency_doc_num'] ?? null)) {
            $currencyId = Currency::query()
                ->where('company_id', $employee->company_id)
                ->where('doc_num', $data['currency_doc_num'])
                ->value('id');
        }

        return HrEmployeeServiceRequest::query()->create([
            'employee_id' => $employee->getKey(),
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'request_type' => $data['request_type'],
            'subject' => $data['subject'] ?? null,
            'details' => $data['details'],
            'requested_from' => $data['requested_from'] ?? null,
            'requested_to' => $data['requested_to'] ?? null,
            'requested_minutes' => $data['requested_minutes'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency_id' => $currencyId,
            'payload' => $data['payload'] ?? null,
            'status' => HrEmployeeServiceRequest::StatusSubmitted,
            'submitted_at' => now(),
            'created_by' => $user->getKey(),
        ]);
    }

    public function cancelForUser(User $user, HrEmployeeServiceRequest $request): HrEmployeeServiceRequest
    {
        $employee = $this->attendance->employeeForUser($user);

        if ((int) $request->employee_id !== (int) $employee->getKey()) {
            throw new DomainException(__('hr_requests.messages.not_owned'));
        }

        return DB::transaction(function () use ($request, $user): HrEmployeeServiceRequest {
            $locked = HrEmployeeServiceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status !== HrEmployeeServiceRequest::StatusSubmitted) {
                throw new DomainException(__('hr_requests.messages.cannot_cancel'));
            }

            $locked->update([
                'status' => HrEmployeeServiceRequest::StatusCancelled,
                'resolved_at' => now(),
                'updated_by' => $user->getKey(),
            ]);

            return $locked->refresh();
        });
    }

    public function review(HrEmployeeServiceRequest $request, User $reviewer, string $decision, ?string $notes): HrEmployeeServiceRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $decision, $notes): HrEmployeeServiceRequest {
            $locked = HrEmployeeServiceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status !== HrEmployeeServiceRequest::StatusSubmitted) {
                throw new DomainException(__('hr_requests.messages.already_resolved'));
            }

            if ((int) $locked->created_by === (int) $reviewer->getKey()) {
                throw new DomainException(__('hr_requests.messages.self_review_not_allowed'));
            }

            $locked->update([
                'status' => $decision,
                'resolved_at' => now(),
                'resolved_by' => $reviewer->getKey(),
                'resolution_notes' => $notes,
                'updated_by' => $reviewer->getKey(),
            ]);

            return $locked->refresh();
        });
    }
}
