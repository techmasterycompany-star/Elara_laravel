<h2>شكرًا لطلبك من GlowThera!</h2>
<p>رقم طلبك: <strong>{{ $order->order_number }}</strong></p>
<p>الحالة الحالية: {{ $order->computedStatus() }}</p>

<h3>تفاصيل الطلب:</h3>
<ul>
    @foreach ($order->items as $item)
        <li>{{ $item->product_name }} × {{ $item->quantity }} — {{ $item->price }} جنيه</li>
    @endforeach
</ul>

<p><strong>الإجمالي: {{ $order->total }} جنيه</strong></p>
<p>عنوان الشحن: {{ $order->shipping_street }}, {{ $order->shipping_city }}</p>