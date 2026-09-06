@if($paginator->hasPages())
    <nav aria-label="{{ __('fixed_assets.product.pagination_label') }}">
        <ul class="pagination pagination-sm flex-wrap justify-content-center mb-3">
            <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                @if($paginator->onFirstPage())
                    <span class="page-link" aria-disabled="true">{{ __('pagination.previous') }}</span>
                @else
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('pagination.previous') }}</a>
                @endif
            </li>

            @foreach($elements as $element)
                @if(is_string($element))
                    <li class="page-item disabled"><span class="page-link">{{ $element }}</span></li>
                @endif
                @if(is_array($element))
                    @foreach($element as $page => $url)
                        <li class="page-item {{ $page === $paginator->currentPage() ? 'active' : '' }}">
                            @if($page === $paginator->currentPage())
                                <span class="page-link" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="page-link" href="{{ $url }}" aria-label="{{ __('fixed_assets.product.page_number', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                @if($paginator->hasMorePages())
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }}</a>
                @else
                    <span class="page-link" aria-disabled="true">{{ __('pagination.next') }}</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
