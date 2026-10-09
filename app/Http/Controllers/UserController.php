<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display all users.
     */
    public function index()
    {
        $users = User::query()
            ->withCount([
                'ingredients',
                'recipes',
            ])
            ->orderBy('id')
            ->get();

        return view(
            'users.index',
            compact('users')
        );
    }

    /**
     * Display the user creation form.
     */
    public function create()
    {
        $permissionGroups = User::permissionGroups();

        return view(
            'users.create',
            compact('permissionGroups')
        );
    }

    /**
     * Store a new user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => [
                'required',
                'string',
                'max:100',
            ],

            'last_name' => [
                'required',
                'string',
                'max:100',
            ],

            'username' => [
                'required',
                'string',
                'max:100',
                Rule::unique('users', 'username'),
                Rule::notIn(['root']),
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],

            'permissions' => [
                'nullable',
                'array',
            ],

            'permissions.*' => [
                'string',
                Rule::in(
                    User::availablePermissions()
                ),
            ],
        ], [
            'username.not_in' => 'The root username is reserved and cannot be used.',
        ]);

        User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make(
                $validated['password']
            ),

            /*
             * Always save an array for new users.
             * An empty selection becomes [] instead of NULL.
             */
            'permissions' => array_values(
                array_unique(
                    $validated['permissions'] ?? []
                )
            ),
        ]);

        return redirect()
            ->route('users.index')
            ->with(
                'success',
                'User created successfully.'
            );
    }

    /**
     * Display the user editing form.
     */
    public function edit(User $user)
    {
        $permissionGroups = User::permissionGroups();

        return view(
            'users.edit',
            compact(
                'user',
                'permissionGroups'
            )
        );
    }

    /**
     * Update an existing user.
     */
    public function update(
        Request $request,
        User $user
    ) {
        $usernameRules = [
            'required',
            'string',
            'max:100',
            Rule::unique('users', 'username')
                ->ignore($user->id),
        ];

        /*
         * No normal user can be renamed to root.
         */
        if (!$user->isRootUser()) {
            $usernameRules[] = Rule::notIn([
                'root',
            ]);
        }

        $validated = $request->validate([
            'first_name' => [
                'required',
                'string',
                'max:100',
            ],

            'last_name' => [
                'required',
                'string',
                'max:100',
            ],

            'username' => $usernameRules,

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'password' => [
                'nullable',
                'string',
                'min:6',
                'confirmed',
            ],

            'permissions' => [
                'nullable',
                'array',
            ],

            'permissions.*' => [
                'string',
                Rule::in(
                    User::availablePermissions()
                ),
            ],
        ], [
            'username.not_in' => 'The root username is reserved and cannot be used.',
        ]);

        $updateData = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'permissions' => array_values(
                array_unique(
                    $validated['permissions'] ?? []
                )
            ),
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make(
                $validated['password']
            );
        }

        /*
         * The root username cannot be changed.
         */
        if ($user->isRootUser()) {
            $updateData['username'] = 'root';

            /*
             * Root always receives all permissions in storage too.
             */
            $updateData['permissions'] =
                User::availablePermissions();
        }

        $user->update($updateData);

        return redirect()
            ->route('users.index')
            ->with(
                'success',
                'User updated successfully.'
            );
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user)
    {
        if ($user->isRootUser()) {
            return redirect()
                ->route('users.index')
                ->with(
                    'error',
                    'The root administrator account cannot be deleted.'
                );
        }

        $ingredientsCount =
            $user->ingredients()->count();

        $recipesCount =
            $user->recipes()->count();

        if (
            $ingredientsCount > 0
            || $recipesCount > 0
        ) {
            $items = [];

            if ($ingredientsCount > 0) {
                $items[] =
                    $ingredientsCount
                    . ' ingredient(s)';
            }

            if ($recipesCount > 0) {
                $items[] =
                    $recipesCount
                    . ' recipe(s)';
            }

            return redirect()
                ->route('users.index')
                ->with(
                    'error',
                    'This user cannot be deleted because they created '
                    . implode(' and ', $items)
                    . '.'
                );
        }

        $user->delete();

        return redirect()
            ->route('users.index')
            ->with(
                'success',
                'User deleted successfully.'
            );
    }
}