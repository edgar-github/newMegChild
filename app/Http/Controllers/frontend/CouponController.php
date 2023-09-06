<?php

namespace App\Http\Controllers\frontend;

use App\Http\Controllers\Controller;
use App\Models\Books;
use App\Models\Coupon;
use App\Services\Frontend\ShopService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * @param ShopService $shopService
     */
    public function __construct(protected ShopService $shopService)
    {

    }

    /**
     * @param Request $request
     * @param bool $includeCoupon
     * @return \Closure|float|\Illuminate\Http\JsonResponse|int|mixed|object|null
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public static function checkCoupon(Request $request, bool $includeCoupon = false): mixed
    {
        $userCoupon = $request->coupon;
        $coupon = self::checkCouponIsValid($userCoupon);

//        dd($coupon);

        if ($coupon) {
            $total_price = 0;
            if ($coupon->type === Coupon::SINGLE_BOOK) {
                $total_price = self::singleCouponFunction($coupon);
            } else if ($coupon->type === Coupon::EACH_BOOKS) {
//                dd($coupon);
                $total_price = self::eachBooksCouponFunction($coupon);
            }


            if ($includeCoupon) {
                return $total_price;
            } else {
                return ShopController::returnTotalPriceResponse($total_price, $coupon, true, __('validation.coupon_used_successfully'));
            }
        }

        return ShopController::returnTotalPriceResponse(ShopService::getCartTotalPrice(), null, false, __('validation.coupon_not_found'));
    }

    /**
     * @param $userCoupon
     * @return mixed
     */
    public static function checkCouponIsValid($userCoupon): mixed
    {
        $coupon = Coupon::where('code', $userCoupon)->where('quantity', '>', 0)->first();

        if ($coupon === null) {
            $couponCode = null;
        } else {
            $couponCode = $coupon->code;
        }

        session()->put('coupon', $couponCode);

        return $coupon;
    }

    /**
     *
     * @param $couponModel
     * @return int|float
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public static function singleCouponFunction($couponModel): int|float
    {
        $total_price = 0;
        if ($couponModel->book_id === Coupon::ALL_BOOKS) {
            $total_price = ShopService::getCartTotalPrice($couponModel->price, $couponModel->book_id, $total_price, Coupon::SINGLE_BOOK);
        } else {
            $total_price = self::filterCouponBookData($couponModel);
        }

        return $total_price;
    }

    /**
     * @param $couponModel
     * @return float|int
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public static function eachBooksCouponFunction($couponModel): float|int
    {
//        dd($couponModel);
        $total_price = 0;
        if ($couponModel->book_id === Coupon::ALL_BOOKS) {
            $total_price = ShopService::getCartTotalPrice($couponModel->price, $couponModel->book_id, $total_price, Coupon::EACH_BOOKS);
        } else {
            $total_price = self::filterCouponBookData($couponModel);
        }

        return $total_price;
    }

    /**
     * @param $couponModel
     * @return float|int
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public static function filterCouponBookData($couponModel): float|int
    {
        $total_price = 0;
        $sessionProductsId = array_column(array_values(session()->get('cart')), "product_id");
        $couponProductsId = json_decode($couponModel->book_id);
        $checkProductsHasCouponIds = [];
        $productsIdWithoutCouponIds = [];

//        dd($sessionProductsId);


        foreach ($sessionProductsId as $value) {
            if (in_array($value, $couponProductsId)) {
                $checkProductsHasCouponIds[] = $value;
            } else {
                $productsIdWithoutCouponIds[] = $value;
            }
        }
//dump($couponModel->price, $checkProductsHasCouponIds, $total_price, $couponModel->type);
        if (count($checkProductsHasCouponIds)) {
            $total_price = ShopService::getCartTotalPrice($couponModel->price, $checkProductsHasCouponIds, $total_price, $couponModel->type);
        }

        if (count($productsIdWithoutCouponIds)) {
            $total_price = ShopService::getCartTotalPrice(false, $productsIdWithoutCouponIds, $total_price, $couponModel->type);
        }

        return $total_price;
    }

}
