@if ($paginator->hasPages())
  <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">
    <div>
      @foreach ($elements as $element)
        @if (is_string($element))
          <span class="pagination-ellipsis" aria-disabled="true">{{ $element }}</span>
        @endif

        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span aria-current="page"><span class="sr-only">Halaman </span>{{ $page }}</span>
            @else
              <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach
    </div>
  </nav>
@endif
