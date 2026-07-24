<x-layouts.app :title="'Edit Membership: ' . $membership->user->name">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Edit Membership</h1>
    <p class="mt-1 text-sm text-stone-500">{{ $membership->user->name }} — {{ $membership->saprf_number ?? 'No SAPRF #' }}</p>

    <form method="POST" action="{{ route('memberships.update', $membership) }}" class="mt-8 max-w-2xl space-y-6">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="email" class="block text-sm font-medium text-stone-700">Email Address</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $membership->user->email) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Used for login, invitations and notifications. Must be unique.</p>
                </div>

                <div>
                    <label for="saprf_number" class="block text-sm font-medium text-stone-700">SAPRF Number</label>
                    <input type="text" name="saprf_number" id="saprf_number" value="{{ old('saprf_number', $membership->saprf_number) }}" class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="membership_type" class="block text-sm font-medium text-stone-700">Membership Type</label>
                    <select name="membership_type" id="membership_type" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="full" @selected(old('membership_type', $membership->membership_type) === 'full')>Full</option>
                        <option value="associate" @selected(old('membership_type', $membership->membership_type) === 'associate')>Associate</option>
                        <option value="junior" @selected(old('membership_type', $membership->membership_type) === 'junior')>Junior</option>
                    </select>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-stone-700">Status</label>
                    <select name="status" id="status" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="pending" @selected(old('status', $membership->status) === 'pending')>Pending</option>
                        <option value="active" @selected(old('status', $membership->status) === 'active')>Active</option>
                        <option value="lapsed" @selected(old('status', $membership->status) === 'lapsed')>Lapsed</option>
                        <option value="suspended" @selected(old('status', $membership->status) === 'suspended')>Suspended</option>
                    </select>
                </div>

                <div>
                    <label for="payment_status" class="block text-sm font-medium text-stone-700">Payment Status</label>
                    <select name="payment_status" id="payment_status" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <option value="pending" @selected(old('payment_status', $membership->payment_status) === 'pending')>Pending</option>
                        <option value="paid" @selected(old('payment_status', $membership->payment_status) === 'paid')>Paid</option>
                        <option value="overdue" @selected(old('payment_status', $membership->payment_status) === 'overdue')>Overdue</option>
                    </select>
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-stone-700">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $membership->start_date?->format('Y-m-d')) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                <div>
                    <label for="expiry_date" class="block text-sm font-medium text-stone-700">Expiry Date</label>
                    <input type="date" name="expiry_date" id="expiry_date" value="{{ old('expiry_date', $membership->expiry_date?->format('Y-m-d')) }}" required class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Update Membership</button>
            <a href="{{ route('memberships.show', $membership) }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
        </div>
    </form>
</x-layouts.app>
