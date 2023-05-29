<?php

namespace App\Services\Frontend;

use App\Jobs\OrderAdminJob;
use App\Jobs\OrderUserJob;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaymentService
{
    public ?string $lang = null; // EDP_LANGUAGE for idram
    private $ameriaIdPrefix = '777';

    public function __construct()
    {
        $this->lang = app()->getLocale() === 'hy' ? 'am' : app()->getLocale();
    }

    public function makePayment(Order $order)
    {
        $amount = $order->total_price_with_discount;
        $order_id = $order->id;
        $payment_method = $order->payment_method;


//       return  match ($payment_method) {
//            Order::PAYMENT_METHOD_IDRAM =>  $this->idramPayment($amount, $order_id),
//            Order::PAYMENT_METHOD_TELCELL => $this->telcellPayment($amount, $order_id),
//            Order::PAYMENT_METHOD_BANK => $this->ameriaPayment($amount, $order_id),
//            default => null,
//        };

        if ($payment_method == Order::PAYMENT_METHOD_IDRAM) {

            return $this->idramPayment($amount, $order_id);

        } elseif ($payment_method == Order::PAYMENT_METHOD_TELCELL) {

            return $this->telcellPayment($amount, $order_id);

        } elseif ($payment_method == Order::PAYMENT_METHOD_BANK) {

            return $this->ameriaPayment($amount, $order_id);
        }

    }


    protected function idramPayment($amount, $order_id)
    {
        $data_idram = [];
        $data_idram['url'] = env('IDRAM_URL');
        $data_idram['EDP_LANGUAGE'] = mb_strtoupper($this->lang);
        $data_idram['EDP_REC_ACCOUNT'] = env('IDRAM_EDP_REC_ACCOUNT');
        $data_idram['EDP_DESCRIPTION'] = 'Վճարում կատարե՛ք idram-ով';
        $data_idram['EDP_AMOUNT'] = $amount;
        $data_idram['EDP_BILL_NO'] = $order_id;

        return view('payments.idram_redirection', compact('data_idram'));
    }

    public function idramCallback(Request $request)
    {
        define("SECRET_KEY", env('IDRAM_SECRET_KEY'));
        define("EDP_REC_ACCOUNT", env('IDRAM_EDP_REC_ACCOUNT'));

        if (isset($request['EDP_PRECHECK']) && isset($request['EDP_BILL_NO']) &&
            isset($request['EDP_REC_ACCOUNT']) && isset($request['EDP_AMOUNT'])) {
            if ($request['EDP_PRECHECK'] == "YES") {
                if ($request['EDP_REC_ACCOUNT'] == EDP_REC_ACCOUNT) {
                    $bill_no = $request['EDP_BILL_NO'];
                    if (Order::where('id', $bill_no)->exists()) {
                        echo "OK";
                    }
                }
            }
        }

        if (isset($request['EDP_PAYER_ACCOUNT']) && isset($request['EDP_BILL_NO']) &&
            isset($request['EDP_REC_ACCOUNT']) && isset($request['EDP_AMOUNT'])
            && isset($request['EDP_TRANS_ID']) && isset($request['EDP_CHECKSUM'])) {
            $order = Order::where('order_payment_id', $request['EDP_BILL_NO'])->first();
            if ($order) {
                $txtToHash =
                    EDP_REC_ACCOUNT . ":" .
                    $request['EDP_AMOUNT'] . ":" .
                    SECRET_KEY . ":" .
                    $request['EDP_BILL_NO'] . ":" .
                    $request['EDP_PAYER_ACCOUNT'] . ":" .
                    $request['EDP_TRANS_ID'] . ":" .
                    $request['EDP_TRANS_DATE'];
                $order->payment_callback = json_encode($request->all());
                if (strtoupper($request['EDP_CHECKSUM']) != strtoupper(md5($txtToHash))) {
                    self::updateOrderBooksPivotStatus($order,Order::STATUS_FAILED);
                } else {
                    self::updateOrderBooksPivotStatus($order,Order::STATUS_COMPLETED);
                    self::dispatchEmailJobs($order);
                    echo "OK";
                }
            }
        }
    }

    protected function telcellPayment($amount, $order_id)
    {
        $data_telcell = [];
        $data_telcell['url'] = env('TELCELL_URL');
        $data_telcell['issuer'] = env('TELCELL_MERCHANT_ID');
        $data_telcell['action'] = 'PostInvoice'; # always PostInvoice
        $data_telcell['currency'] = "֏"; # always ֏
        $data_telcell['price'] = $amount;
        $data_telcell['product'] = base64_encode('Վճարումն իրականացրե՛ք Telcell Wallet-ով: Խնդրում ենք նկատի ունենալ՝ վճարումն իրականացվելու է հայկական դրամով:');  # description always in base64
        $data_telcell['issuer_id'] = base64_encode($order_id); # order id always in base64
        $data_telcell['valid_days'] = 1; # Число дней, в течении которых счёт действителен.
        $data_telcell['lang'] = $this->lang;
        $data_telcell['security_code'] = $this->getTelcellSecurityCode(
            env('TELCELL_KEY'),
            $data_telcell['issuer'],
            $data_telcell['currency'],
            $data_telcell['price'],

            $data_telcell['product'],
            $data_telcell['issuer_id'],
            $data_telcell['valid_days']
        );
        return view('payments.telcell_redirection', compact('data_telcell'));
    }

    public function telcellCallback(Request $request)
    {
        if (!(
            $request->has('issuer_id') &&
            $request->has('checksum') &&
            $request->has('invoice') &&
            $request->has('issuer_id') &&
            $request->has('payment_id') &&
            $request->has('currency') &&
            $request->has('sum') &&
            $request->has('time') &&
            $request->has('status'))) {

            abort(404);
        }
//        $order = Order::find($request->issuer_id);
        $order = Order::where('order_payment_id', $request->issuer_id)->first();

        if (!$order)
            abort(404);

        $new_checksum = hash('md5', env('TELCELL_KEY') . $request->invoice . $request->issuer_id . $request->payment_id . $request->currency . $request->sum . $request->time . $request->status);
        if ($request->checksum != $new_checksum) {
            $order->payment_callback = 'telcell checksum failed';
            self::updateOrderBooksPivotStatus($order,Order::STATUS_FAILED);

            abort(404);
        }

        $order->payment_callback = json_encode($request->all());
        if ($request->status == 'PAID') {
            self::updateOrderBooksPivotStatus($order,Order::STATUS_COMPLETED);
            self::dispatchEmailJobs($order);
        } else {
            self::updateOrderBooksPivotStatus($order,Order::STATUS_FAILED);
        }
    }



    public function telcellRedirect(Request $request): \Illuminate\Http\RedirectResponse
    {
        if (!$request->has('order')) {
            abort(404);
        }
//        $order = Order::find($request->order);
        $order = Order::where('order_payment_id', $request->order)->first();

        if (!$order)
            abort(404);

        if ($order->status == Order::STATUS_COMPLETED) {
            return redirect()->route('payment.success');
        } else {
            return redirect()->route('payment.fail');
        }
    }

    protected function getTelcellSecurityCode($shop_key, $issuer, $currency, $price, $product, $issuer_id, $valid_days): string
    {
        return hash('md5', $shop_key . $issuer . $currency . $price . $product . $issuer_id . $valid_days);
    }

    public function ameriaPayment($amount, $order_id)
    {
        $url = env('AMERIA_PAYMENT_URL') . env('AMERIA_PAYMENT_EDP_INIT_URL');
        $id = $this->ameriaIdPrefix . $order_id;
        $data = [
            'ClientID' => env('AMERIA_CLIENT_ID'),
            'Username' => env('AMERIA_USERNAME'),
            'Password' => env('AMERIA_PASSWORD'),
            'Currency' => 'AMD',
            'Description' => 'Danz.am - Visa,Mastercard,ArCA',
            'OrderID' => $id,
            'Amount' => $amount,


            'BackURL' => route('payment.ameria_callback'),
            'Opaque' => '' #TODO: add Opaque
        ];

        $req = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($url, $data);

        $response = $req->json();
        $lang = $this->lang;
        if ($response["ResponseCode"] === 1) {
            return redirect()->away("https://services.ameriabank.am/VPOS/Payments/Pay?id={$response['PaymentID']}&lang={$lang}");
        } else {
            $order = Order::find($order_id);

            $order->update([
                'status' => Order::STATUS_FAILED,
                'payment_callback' => json_encode($response)
            ]);

            self::updateOrderBooksPivotStatus($order,Order::STATUS_FAILED);

            return redirect()->route('payment.fail');
        }
    }

    public function ameriaCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        //log request
        try {
            info($request->all());
        } catch (\Exception $e) {
            info($e->getMessage());
            info('Ameria callback request log error');
        }
        $error = false;
        if (!$request->has("orderID"))
            abort(404);

        $order_id = substr($request->orderID, strlen($this->ameriaIdPrefix));
//        $order = Order::find($order_id);
        $order = Order::where('order_payment_id', $order_id)->first();


        if ($request->paymentID) {
            $url = env('AMERIA_PAYMENT_URL') . env('AMERIA_PAYMENT_EDP_PAYMENT_DETAILS_URL');
            $data = [
                "PaymentID" => $request->paymentID,
                "Username" => env("AMERIA_USERNAME"),
                "Password" => env("AMERIA_PASSWORD"),
            ];
            $req = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($url, $data);
            $response = $req->json();
            $order->payment_callback = json_encode($response);
        }


        switch ($request->resposneCode) {
            case '00':
                self::updateOrderBooksPivotStatus($order,Order::STATUS_COMPLETED);
                break;
            default:
                $error = true;
                self::updateOrderBooksPivotStatus($order,Order::STATUS_FAILED);
        }

        if ($error) {
            return redirect()->route('payment.fail');
        } else {
            self::dispatchEmailJobs($order);

            return redirect()->route('payment.success');
        }
    }

    /**
     * @param $order
     * @param $status
     * @return void
     */
    public static function updateOrderBooksPivotStatus($order, $status): void
    {
        $order->status = $status;

        $orderProducts = $order->books;
        foreach ($orderProducts as $orderProduct) {
            $order->books()->updateExistingPivot($orderProduct->id, [
                'status' => $status
            ]);
        }
        $order->save();
    }

    /**
     * @param Order $order
     * @return void
     */
    public static function dispatchEmailJobs(Order $order): void
    {
        OrderAdminJob::dispatch($order);
        OrderUserJob::dispatch($order);
    }

}