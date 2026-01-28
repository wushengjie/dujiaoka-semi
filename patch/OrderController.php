<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Order;
use App\Models\Coupon;
use App\Models\Goods;
use App\Models\Pay;
use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Order as OrderModel;

class OrderController extends AdminController
{


    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Order(['goods', 'coupon', 'pay']), function (Grid $grid) {
            $grid->model()->orderBy('id', 'DESC');
            $isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|Windows Phone/i', request()->header('User-Agent'));
            if ($isMobile) {
                $grid->column('operate', '操作')->display(function () {
                    $status = $this->status;
                    $type = $this->type;
                    if (in_array($status, [OrderModel::STATUS_WAIT_PAY, OrderModel::STATUS_PENDING, OrderModel::STATUS_EXPIRED]) && $type == OrderModel::AUTOMATIC_DELIVERY) {
                        $url = url(config('admin.route.prefix').'/order/'.$this->id).'?deliver=1';
                        return '<a class="btn btn-sm btn-success deliver-btn" href="'.$url.'" data-url="'.$url.'">发货</a>';
                    }
                    return '';
                });
                $grid->disableRowSelector();
                $grid->column('id')->sortable();
                $grid->column('order_sn')->copyable();
                $grid->column('title')->limit(16);
                $grid->column('type')->using(OrderModel::getTypeMap())
                    ->label([
                        OrderModel::AUTOMATIC_DELIVERY => Admin::color()->success(),
                        OrderModel::MANUAL_PROCESSING => Admin::color()->info(),
                    ]);
                $grid->column('email')->display(function ($value) {
                    return \Illuminate\Support\Str::limit($value, 18, '...');
                })->copyable();
                $grid->column('actual_price');
                $grid->column('status')->select(OrderModel::getStatusMap());
                $grid->column('created_at');
            } else {
                $grid->column('id')->sortable();
                $grid->column('order_sn')->copyable();
                $grid->column('title');
                $grid->column('type')->using(OrderModel::getTypeMap())
                    ->label([
                        OrderModel::AUTOMATIC_DELIVERY => Admin::color()->success(),
                        OrderModel::MANUAL_PROCESSING => Admin::color()->info(),
                    ]);
                $grid->column('email')->copyable();
                $grid->column('goods.gd_name', admin_trans('order.fields.goods_id'));
                $grid->column('goods_price');
                $grid->column('buy_amount');
                $grid->column('total_price');
                $grid->column('coupon.coupon', admin_trans('order.fields.coupon_id'));
                $grid->column('coupon_discount_price');
                $grid->column('wholesale_discount_price');
                $grid->column('actual_price');
                $grid->column('pay.pay_name', admin_trans('order.fields.pay_id'));
                $grid->column('buy_ip');
                $grid->column('search_pwd')->copyable();
                $grid->column('trade_no')->copyable();
                $grid->column('status')->select(OrderModel::getStatusMap());
                $grid->column('created_at');
                $grid->column('updated_at')->sortable();
            }
            $grid->disableCreateButton();
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('order_sn');
                $filter->like('title');
                $filter->equal('status')->select(OrderModel::getStatusMap());
                $filter->equal('email');
                $filter->equal('trade_no');
                $filter->equal('type')->select(OrderModel::getTypeMap());
                $filter->equal('goods_id')->select(Goods::query()->pluck('gd_name', 'id'));
                $filter->equal('coupon_id')->select(Coupon::query()->pluck('coupon', 'id'));
                $filter->equal('pay_id')->select(Pay::query()->pluck('pay_name', 'id'));
                $filter->whereBetween('created_at', function ($q) {
                    $start = $this->input['start'] ?? null;
                    $end = $this->input['end'] ?? null;
                    $q->where('created_at', '>=', $start)
                        ->where('created_at', '<=', $end);
                })->datetime();
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) use ($isMobile) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(OrderModel::class));
                } else {
                    if (!$isMobile) {
                        $status = $actions->row->status;
                        $type = $actions->row->type;
                        if (in_array($status, [OrderModel::STATUS_WAIT_PAY, OrderModel::STATUS_PENDING, OrderModel::STATUS_EXPIRED]) && $type == OrderModel::AUTOMATIC_DELIVERY) {
                            $url = url(config('admin.route.prefix').'/order/'.$actions->row->id).'?deliver=1';
                            $actions->append('<a class="btn btn-sm btn-success deliver-btn" href="'.$url.'" data-url="'.$url.'">确认付款成功并发货</a>');
                        }
                    }
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(OrderModel::class));
                }
            });
            Admin::script('$(document).off("click",".deliver-btn").on("click",".deliver-btn",function(e){e.preventDefault();var t=$(this);var u=t.data("url");t.prop("disabled",true).addClass("disabled");$.ajax({url:u,type:"GET"}).done(function(){if(window.Dcat&&Dcat.success){Dcat.success("操作成功");}else{alert("操作成功");}t.remove();}).fail(function(){if(window.Dcat&&Dcat.error){Dcat.error("操作失败");}else{alert("操作失败");}t.prop("disabled",false).removeClass("disabled");});});');
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        if (request()->get('deliver') == 1) {
            $ord = OrderModel::query()->with('goods')->find($id);
            if ($ord && $ord->type == OrderModel::AUTOMATIC_DELIVERY && in_array($ord->status, [OrderModel::STATUS_WAIT_PAY, OrderModel::STATUS_PENDING, OrderModel::STATUS_EXPIRED])) {
                app('Service\OrderProcessService')->completedOrder($ord->order_sn, (float)$ord->actual_price, 'offline');
            }
        }
        return Show::make($id, new Order(['goods', 'coupon', 'pay']), function (Show $show) {
            $show->field('id');
            $show->field('order_sn');
            $show->field('title');
            $show->field('email');
            $show->field('goods.gd_name', admin_trans('order.fields.goods_id'));
            $show->field('goods_price');
            $show->field('buy_amount');
            $show->field('coupon.coupon', admin_trans('order.fields.coupon_id'));
            $show->field('coupon_discount_price');
            $show->field('wholesale_discount_price');
            $show->field('total_price');
            $show->field('actual_price');
            $show->field('buy_ip');
            $show->field('info')->unescape()->as(function ($info) {
                return  "<textarea class=\"form-control field_wholesale_price_cnf _normal_\"  rows=\"10\" cols=\"30\">" . $info . "</textarea>";
            });
            $show->field('pay.pay_name', admin_trans('order.fields.pay_id'));
            $show->field('status')->using(OrderModel::getStatusMap());
            $show->field('search_pwd');
            $show->field('trade_no');
            $show->field('type')->using(OrderModel::getTypeMap());
            $show->field('created_at');
            $show->field('updated_at');
            $show->disableEditButton();
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new Order(['goods', 'coupon', 'pay']), function (Form $form) {
            $form->display('id');
            $form->display('order_sn');
            $form->text('title');
            $form->display('goods.gd_name', admin_trans('order.fields.goods_id'));
            $form->display('goods_price');
            $form->display('buy_amount');
            $form->display('coupon.coupon', admin_trans('order.fields.coupon_id'));
            $form->display('coupon_discount_price');
            $form->display('wholesale_discount_price');
            $form->display('total_price');
            $form->display('actual_price');
            $form->display('email');
            $form->textarea('info');
            $form->display('buy_ip');
            $form->display('pay.pay_name', admin_trans('order.fields.pay_id'));
            $form->radio('status')->options(OrderModel::getStatusMap());
            $form->text('search_pwd');
            $form->display('trade_no');
            $form->radio('type')->options(OrderModel::getTypeMap());
            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}
