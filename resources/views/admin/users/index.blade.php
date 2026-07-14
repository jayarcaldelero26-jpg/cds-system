<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('User Management') }}
            </h2>

            <a href="{{ route('admin.users.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                {{ __('Add User') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    @if (session('status'))
                        <div class="mb-6 rounded-md bg-green-50 p-4 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            @if (session('status') === 'user-created')
                                {{ __('User created successfully.') }}
                            @elseif (session('status') === 'user-updated')
                                {{ __('User updated successfully.') }}
                            @elseif (session('status') === 'user-deleted')
                                {{ __('User deleted successfully.') }}
                            @endif
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Name') }}</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Email') }}</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Role') }}</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Status') }}</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Created') }}</th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                @forelse ($users as $user)
                                    @php($role = $user->roles->first()?->name)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</td>
                                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $user->email }}</td>
                                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $user->roles->pluck('name')->join(', ') ?: __('No role') }}</td>
                                        <td class="whitespace-nowrap px-4 py-4 text-sm">
                                            @if ($user->is_active)
                                                <span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300">{{ __('Active') }}</span>
                                            @else
                                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-800 dark:bg-gray-700 dark:text-gray-300">{{ __('Inactive') }}</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $user->created_at->format('M d, Y') }}</td>
                                        <td class="whitespace-nowrap px-4 py-4 text-right text-sm font-medium">
                                            <div class="flex justify-end gap-3">
                                                <a href="{{ route('admin.users.edit', $user) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">{{ __('Edit') }}</a>

                                                @if ($role)
                                                    <form method="POST" action="{{ route('admin.users.update', $user) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="name" value="{{ $user->name }}">
                                                        <input type="hidden" name="email" value="{{ $user->email }}">
                                                        <input type="hidden" name="role" value="{{ $role }}">
                                                        <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                                                        <button type="submit" class="{{ $user->is_active ? 'text-amber-600 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-300' : 'text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300' }}">
                                                            {{ $user->is_active ? __('Deactivate') : __('Activate') }}
                                                        </button>
                                                    </form>
                                                @endif

                                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('{{ __('Are you sure you want to delete this user? This action cannot be undone.') }}');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No users found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($users->hasPages())
                        <div class="mt-6">
                            {{ $users->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
