<?php

namespace App\Http\Controllers;

use App\Actions\RecordActivity;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['semua', 'tetap'])],
        ]);
        $search = trim($filters['q'] ?? '');
        $loyalOnly = ($filters['status'] ?? 'semua') === 'tetap';

        $customers = Customer::query()
            ->withCount(['sales' => fn ($query) => $query->notCancelled()])
            ->withSum(['sales' => fn ($query) => $query->notCancelled()], 'total')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->when($loyalOnly, fn ($query) => $query->whereHas(
                'sales',
                fn ($sales) => $sales->notCancelled(),
                '>=',
                Customer::LOYAL_SALE_THRESHOLD,
            ))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('pelanggan', [
            'customers' => $customers,
            'filters' => [
                'q' => $search,
                'status' => $loyalOnly ? 'tetap' : 'semua',
            ],
            'selectedCustomer' => null,
            'sales' => null,
        ]);
    }

    public function show(Customer $customer): View
    {
        $customer->loadCount(['sales' => fn ($query) => $query->notCancelled()])
            ->loadSum(['sales' => fn ($query) => $query->notCancelled()], 'total');
        $sales = $customer->sales()
            ->withCount('items')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('pelanggan', [
            'customers' => null,
            'filters' => ['q' => '', 'status' => 'semua'],
            'selectedCustomer' => $customer,
            'sales' => $sales,
        ]);
    }

    public function store(Request $request, RecordActivity $recordActivity): RedirectResponse
    {
        $customer = DB::transaction(function () use ($request, $recordActivity): Customer {
            $customer = Customer::create($this->validatedCustomer($request));
            $recordActivity->handle(
                $request->user(),
                'customer.created',
                "Menambahkan pelanggan {$customer->name}.",
                $customer,
            );

            return $customer;
        });

        return to_route('pelanggan.show', $customer)->with('success', 'Pelanggan berhasil ditambahkan.');
    }

    public function update(Request $request, Customer $customer, RecordActivity $recordActivity): RedirectResponse
    {
        DB::transaction(function () use ($request, $customer, $recordActivity): void {
            $customer->update($this->validatedCustomer($request));
            $recordActivity->handle(
                $request->user(),
                'customer.updated',
                "Memperbarui data pelanggan {$customer->name}.",
                $customer,
            );
        });

        return to_route('pelanggan.show', $customer)->with('success', 'Data pelanggan berhasil diperbarui.');
    }

    private function validatedCustomer(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]+$/'],
        ], [
            'phone.regex' => 'Nomor WhatsApp hanya boleh berisi angka, spasi, dan tanda + ( ) . -.',
        ]);
    }
}
