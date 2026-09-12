<?php

namespace App\Http\Controllers;
use App\Services\WalletService;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class BalanceController extends Controller
{
    public function index(Request $request): View { $id=$request->attributes->get('legacyUser')->id; return view('balance.index',['user'=>DB::table('users')->where('id',$id)->first(),'history'=>DB::table('transactions')->where('user_id',$id)->orderByDesc('seq')->limit(50)->get(),'topups'=>DB::table('topups')->where('user_id',$id)->orderByDesc('created_at')->limit(10)->get()]); }
    public function topup(Request $request, WalletService $wallet, CheckoutService $checkout): RedirectResponse { $data=$request->validate(['amount'=>['required','integer','min:1','max:1000000'],'idempotency_key'=>['required','string','max:128']]); $topup=$wallet->createTopup($request->attributes->get('legacyUser')->id,$data['amount']*100,$data['idempotency_key']); $checkout->createTopup($topup->id); return redirect('/balance/topup/'.$topup->id,303); }
    public function show(Request $request,string $id): View { $topup=DB::table('topups')->where('id',$id)->where('user_id',$request->attributes->get('legacyUser')->id)->first(); abort_unless($topup,404); return view('balance.topup',compact('topup')); }
    public function demo(Request $request,string $id,WalletService $wallet): RedirectResponse { $wallet->settleDemo($id,$request->attributes->get('legacyUser')->id); return redirect('/balance',303); }
}
