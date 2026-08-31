<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <!-- Main Container para sa Sidebar ug Content -->
    <div class="flex min-h-screen">
        <!-- Sidebar (Berde nga Background) -->
        <div class="w-64 bg-[#0a3d20] text-white flex flex-col">
            <!-- Logo / Header -->
            <div class="p-4 flex items-center space-x-3 border-b border-emerald-800">
                <span class="font-bold text-lg">eDATS</span>
            </div>

            <!-- Sidebar Navigation Links -->
            <div class="flex-1 py-4 space-y-1">
                <a href="{{ route('dashboard') }}" class="block px-4 py-2 hover:bg-emerald-900 bg-emerald-800 text-white font-medium rounded-md mx-2">
                    Dashboard
                </a>

                <a href="{{ route('protected-areas.index') }}" class="block px-4 py-2 hover:bg-emerald-900 text-gray-200 rounded-md mx-2">
                    Protected Area Management
                </a>

                <a href="#" class="block px-4 py-2 hover:bg-emerald-900 text-gray-200 rounded-md mx-2">
                    Ecotourism Impact Monitoring
                </a>

                <a href="#" class="block px-4 py-2 hover:bg-emerald-900 text-gray-200 rounded-md mx-2">
                    Management Plans
                </a>


                <a href="#" class="block px-4 py-2 hover:bg-emerald-900 text-gray-200 rounded-md mx-2">
                    Issues Monitoring
                </a>

                <a href="#" class="block px-4 py-2 hover:bg-emerald-900 text-gray-200 rounded-md mx-2">
                    Programs, Projects & Activities
                </a>

                <!-- LAWIN Menu: Matago dayon kung Technical Staff ang mo-log in -->
                @if (!auth()->user()->hasRole('Technical Staff'))
                    <a href="{{ route('lawin.index') }}" class="block px-4 py-2 hover:bg-emerald-900 text-gray-200 rounded-md mx-2">
                        LAWIN Monitoring System
                    </a>
                @endif
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="flex-1 py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        {{ __("You're logged in!") }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
