<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public string $newStatus)
    {
        $this->order->loadMissing('items');
    }

    public function build()
    {
        return $this->subject("تحديث حالة طلبك رقم {$this->order->order_number}")
            ->view('emails.order-status-changed');
    }
}