<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use App\Models\Transportation;
use Illuminate\Http\Request;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;

class TransportationController  extends Controller
{
    public function create()
    {
        return view('transportation.create');
    }


    public function index()
    {
        $user = auth()->user();

        if ($user?->is_admin) {
            // 管理者：全ユーザー分の交通費申請
            $expenses = Expense::with('transportationExpenses', 'user')
                ->where('expense_type', 'transportation') // transportation固定
                ->orderBy('id', 'desc')
                ->get();
        } else {
            // 一般ユーザ：自分の申請のみ
            $expenses = Expense::with('transportationExpenses')
                ->where('user_id', $user->id)
                ->where('expense_type', 'transportation')
                ->orderBy('id', 'desc')
                ->get();
        }

        return view('transportation.index', compact('expenses'));
    }





    public function show($id)
    {
        $item = Transportation::findOrFail($id);
        return view('transportation.show', compact('item'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transportation.*.use_date'   => 'required|date',
            'transportation.*.departure'  => 'required|string',
            'transportation.*.arrival'    => 'required|string',
            'transportation.*.route'      => 'required|string',
            'transportation.*.amount'     => 'required|numeric',
        ]);

        DB::transaction(function () use ($request, $validated) {
            $userId = auth()->id();
            $totalAmount = 0;
            $descriptions = [];

            // ① Expenseを先に作る（明細を紐づけるための伝票ID）
            $expense = Expense::create([
                'user_id'      => $userId,
                'date'         => now(),
                'amount'       => 0,       // 後で更新
                'description'  => '',      // 後で更新
                'expense_type' => 'transportation',
                'status'       => 'draft',
            ]);
            // ② 明細を登録して紐づけ、合計金額も計算
            foreach ($request->input('transportation_expenses') as $index => $data) {
                TransportationExpense::create([
                    'user_id'       => $userId,
                    'expense_id'    => $expense->id,
                    'display_order' => $index,
                    'use_date'      => $data['use_date'],
                    'departure'     => $data['departure'],
                    'arrival'       => $data['arrival'],
                    'route'         => $data['route'],
                    'amount'        => $data['amount'],
                    'remarks'       => $data['remarks'] ?? null,
                ]);

                $totalAmount += $data['amount'];
                $descriptions[] = $data['route'];
            }

            // ③ Expense を更新（合計金額・概要）
            $expense->update([
                'amount'      => $totalAmount,
                'description' => implode(' / ', $descriptions),
            ]);
        });

        return redirect()
            ->route('transportation.index')
            ->with('success', '登録が完了しました！');
    }




    public function edit($id)
    {
        $expense = Expense::with('transportationExpenses')->findOrFail($id);
        return view('transportation.edit', compact('expense'));
    }


    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'use_date' => 'required|date',
            'departure' => 'required|string',
            'arrival' => 'required|string',
            'route' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        DB::transaction(function () use ($validated, $id) {
            $transport = TransportationExpense::findOrFail($id);
            $transport->update($validated);

            // Expense テーブルを transportation_expense_id で更新
            Expense::where('transportation_expense_id', $transport->id)
                ->update([
                    'date'        => $transport->use_date,
                    'amount'      => $transport->amount,
                    'description' => $transport->route,
                ]);
        });

        return redirect()
            ->route('transportation.index')
            ->with('success', '更新が完了しました！');
    }

    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            $transport = TransportationExpense::findOrFail($id);

            // expenses 側も削除（descriptionやuse_dateでマッチ）
            Expense::where([
                ['expense_type', 'transportation'],
                ['user_id', $transport->user_id],
                ['date', $transport->use_date],
                ['description', $transport->route],
            ])->latest()->first()?->delete();

            // transportation_expenses も削除
            $transport->delete();
        });

        return redirect()
            ->route('transportation.index')
            ->with('success', '削除が完了しました！');
    }

    // app/Http/Controllers/TransportationExpenseController.php

    public function resubmit(Request $request, $id)
    {
        $transport = TransportationExpense::findOrFail($id);

        // 再申請可能な条件（本人＆差戻し状態）
        if ($transport->user_id !== auth()->id() || $transport->expense->status !== 'returned') {
            abort(403);
        }

        // 申請データをバリデーション＆更新
        $validated = $request->validate([
            'use_date' => 'required|date',
            'departure' => 'required|string',
            'arrival' => 'required|string',
            'route' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        DB::transaction(function () use ($transport, $validated) {
            $transport->update($validated);

            $expense = $transport->expense;
            $expense->update([
                'date' => $validated['use_date'],
                'amount' => $validated['amount'],
                'description' => $validated['route'],
                'status' => 'submitted',
                'approval_comment' => null,
                'approved_at' => null,
                'approver_id' => null,
            ]);
        });

        return redirect()->route('expenses.index')->with('success', '再申請が完了しました');
    }
}
