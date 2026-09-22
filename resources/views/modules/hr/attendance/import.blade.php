@extends('layouts.app')

@section('title', __('hr_attendance.import.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="row g-3">
            <div class="col-12 col-xl-8">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ __('hr_attendance.import.title') }}</h5></div>
                    <div class="card-body">
                        <p class="text-700">{{ __('hr_attendance.import.description') }}</p>
                        <form method="POST" action="{{ route('admin.hr.employee-attendance.import.store') }}" enctype="multipart/form-data" class="row g-3">
                            @csrf
                            <div class="col-12">
                                <x-forms.label for="biometric_device_doc_num" :label="__('hr_attendance.import.device')" :required="true" />
                                <x-forms.select id="biometric_device_doc_num" name="biometric_device_doc_num" variant="ajax" :url="route('admin.hr.select2.foundation', 'biometric-devices')" :placeholder="__('common.placeholders.select')" :allow-clear="false" required>
                                    @if ($selectedDevice)
                                        <option value="{{ $selectedDevice->doc_num }}" selected>{{ $selectedDevice->name }} / {{ $selectedDevice->doc_num }}</option>
                                    @endif
                                </x-forms.select>
                            </div>
                            <div class="col-12">
                                <x-forms.label for="attendance_workbook" :label="__('hr_attendance.import.workbook')" :required="true" />
                                <label class="border border-2 border-dashed rounded-3 p-4 w-100 text-center bg-body-tertiary" for="attendance_workbook" style="cursor:pointer">
                                    <span class="fas fa-file-excel fs-2 text-success d-block mb-2" aria-hidden="true"></span>
                                    <strong class="d-block mb-1">{{ __('hr_attendance.import.choose_file') }}</strong>
                                    <span class="small text-muted" id="attendance-workbook-name">{{ __('hr_attendance.import.no_file') }}</span>
                                </label>
                                <input class="visually-hidden" id="attendance_workbook" name="workbook" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit"><span class="fas fa-file-import me-1" aria-hidden="true"></span>{{ __('hr_attendance.import.action') }}</button>
                                <a class="btn btn-falcon-default" href="{{ route('admin.hr.employee-attendance.index') }}">{{ __('common.actions.back') }}</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-4">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">{{ __('hr_attendance.import.format_title') }}</h6></div>
                    <div class="card-body small text-700">
                        <ol class="mb-3 ps-3">
                            @foreach (__('hr_attendance.import.steps') as $step)<li class="mb-2">{{ $step }}</li>@endforeach
                        </ol>
                        <div class="alert alert-info mb-0">{{ __('hr_attendance.import.inference_help') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('attendance_workbook')?.addEventListener('change', event => {
            document.getElementById('attendance-workbook-name').textContent = event.currentTarget.files?.[0]?.name || @json(__('hr_attendance.import.no_file'));
        });
    </script>
@endpush
