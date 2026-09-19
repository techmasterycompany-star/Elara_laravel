<h2>تحديث على طلبك رقم {{ $order->order_number }}</h2>
<p>حالة طلبك دلوقتي: <strong>{{ $newStatus }}</strong></p>

<h3>تفاصيل الطلب:</h3>
<ul>
    @foreach ($order->items as $item)
        <li>{{ $item->product_name }} × {{ $item->quantity }} — {{ $item->status }}</li>
    @endforeach
</ul>