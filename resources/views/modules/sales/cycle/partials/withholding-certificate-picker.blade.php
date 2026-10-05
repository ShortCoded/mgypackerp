@can('file_manager.view')
<p class="small mt-2">{{ __('sales_ui.wht.certificate_required') }}</p>
<button type="button" class="btn btn-falcon-default btn-sm js-sales-attachment-picker" data-remove-label="{{ __('Remove attachment') }}" data-file-picker data-picker-accept="document" data-picker-max="1" data-picker-title="{{ __('Choose attachment') }}" data-picker-collection="withholding_certificate" data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}">{{ __('Choose attachment') }}</button><div class="js-sales-attachment-inputs"></div><ul class="list-group mt-2 js-sales-selected-attachments d-none"></ul>
@endcan
