@php
  $displayPrice = $settings->promo_enabled && $product->promo_price !== null
    ? $product->promo_price
    : $product->sale_price;
@endphp
<article class="pcard pos-product"
  data-id="{{ $product->id }}"
  data-name="{{ $product->name }}"
  data-unit="{{ $product->unit }}"
  data-sale-price="{{ $product->sale_price }}"
  data-promo-price="{{ $settings->promo_enabled ? $product->promo_price : '' }}"
  data-wholesale-price="{{ $settings->wholesale_enabled ? $product->wholesale_price : '' }}"
  data-wholesale-min-qty="{{ $settings->wholesale_enabled ? $product->wholesale_min_qty : '' }}"
  data-wholesale-group-id="{{ $settings->wholesale_enabled ? $product->wholesale_group_id : '' }}"
  data-wholesale-group-name="{{ $settings->wholesale_enabled ? $product->wholesaleGroup?->name : '' }}"
  data-wholesale-group-min-qty="{{ $settings->wholesale_enabled ? $product->wholesaleGroup?->minimum_quantity : '' }}"
  data-stock="{{ $product->tracks_stock ? $product->stock_qty : '' }}"
  data-tracks-stock="{{ $product->tracks_stock ? '1' : '0' }}">
  <div class="img">
    @if($product->photo_path)
      <img class="product-photo" src="{{ \Illuminate\Support\Facades\Storage::url($product->photo_path) }}" alt="{{ $product->name }}" loading="lazy">
    @else
      <span>{{ $product->tracks_stock ? 'Produk' : 'Jasa' }}</span>
    @endif
    @if($settings->promo_enabled && $product->promo_price)<span class="pill c-orange promo-label">Promo</span>@endif
  </div>
  <div class="body">
    <p class="product-category">{{ $product->category->name }}{{ $product->sku ? ' · '.$product->sku : '' }}</p>
    <h4>{{ $product->name }}</h4>
    <div class="price">{{ $money($displayPrice) }}</div>
    @if($settings->promo_enabled && $product->promo_price !== null)
      <div class="meta"><span>Harga promo</span><span>Normal {{ $money($product->sale_price) }}</span></div>
    @endif
    <div class="meta">
      <span>{{ $product->tracks_stock ? 'Stok '.$product->stock_qty.' '.$product->unit : 'Jasa · '.$product->unit }}</span>
      <span>
        @if($settings->wholesale_enabled && $product->wholesale_price !== null)
          @if($product->wholesaleGroup)
            {{ $product->wholesaleGroup->name }} · Grosir ≥ {{ $product->wholesaleGroup->minimum_quantity }} {{ $product->unit }} gabungan
          @elseif($product->wholesale_min_qty !== null)
            Grosir ≥ {{ $product->wholesale_min_qty }}: {{ $money($product->wholesale_price) }}
          @endif
        @endif
      </span>
    </div>
    <button class="btn sm primary add-to-cart" type="button">Tambah</button>
  </div>
</article>
