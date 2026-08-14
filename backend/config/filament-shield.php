<?php

declare(strict_types=1);
use App\Modules\Tenancy\Support\PermissionLabels;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

/*
|--------------------------------------------------------------------------
| Shield is the SCREEN here, never the source of the permissions
|--------------------------------------------------------------------------
|
| The package's usual job is to DERIVE permission names from your Filament
| resources — `View:Course`, `Update:Lesson` and so on. This product already has
| seventy-two of them as constants in `Tenancy\Support\Permissions`, read by
| every policy, route and test, and the repository's rule is that permission
| names come from those constants and nowhere else.
|
| So both generators are off and the list is handed over instead:
|
|   · `permissions.generate = false`   — Shield invents no names
|   · `policies.generate  = false`     — the policies here are hand-written, and
|                                        each carries the reasoning for its refusals
|   · `format_custom_permission_keys`  — MUST stay false: our names are dotted
|                                        (`billing.audit.view`) and the formatter
|                                        would pascal-case them into strings no
|                                        policy has ever heard of
|   · `super_admin` / `panel_user`     — off; super-admin here is the
|                                        `users.is_super_admin` column, and a
|                                        second one wearing a role would be two
|                                        answers to one question
|
| What is left is the part that was actually wanted: a screen for ticking
| permissions onto a role.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Shield Resource
    |--------------------------------------------------------------------------
    |
    | Here you may configure the built-in role management resource. You can
    | customize the URL, choose whether to show model paths, group it under
    | a cluster, and decide which permission tabs to display.
    |
    */

    'shield_resource' => [
        'slug' => 'shield/roles',
        'show_model_path' => true,
        'cluster' => null,
        // Only the custom tab, because only the custom tab has anything in it:
        // the other three list permissions Shield would have generated, and it
        // generates none.
        'tabs' => [
            'pages' => false,
            'widgets' => false,
            'resources' => false,
            'custom_permissions' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When your application supports teams, Shield will automatically detect
    | and configure the tenant model during setup. This enables tenant-scoped
    | roles and permissions throughout your application.
    |
    */

    'tenant_model' => null,

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | This value contains the class name of your user model. This model will
    | be used for role assignments and must implement the HasRoles trait
    | provided by the Spatie\Permission package.
    |
    */

    'auth_provider_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | Here you may define a super admin that has unrestricted access to your
    | application. You can choose to implement this via Laravel's gate system
    | or as a traditional role with all permissions explicitly assigned.
    |
    */

    'super_admin' => [
        'enabled' => false,
        'name' => 'super_admin',
        'define_via_gate' => false,
        'intercept_gate' => 'before',
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel User
    |--------------------------------------------------------------------------
    |
    | When enabled, Shield will create a basic panel user role that can be
    | assigned to users who should have access to your Filament panels but
    | don't need any specific permissions beyond basic authentication.
    |
    */

    'panel_user' => [
        'enabled' => false,
        'name' => 'panel_user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Builder
    |--------------------------------------------------------------------------
    |
    | You can customize how permission keys are generated to match your
    | preferred naming convention and organizational standards. Shield uses
    | these settings when creating permission names from your resources.
    |
    | Supported formats: snake, kebab, pascal, camel, upper_snake, lower_snake
    |
    | Note: The separator must not conflict with the case format's own
    | delimiter. For example, `_` cannot be used with snake/lower_snake/
    | upper_snake, and `-` cannot be used with kebab.
    |
    | When `format_custom_permission_keys` is true (default), custom
    | permissions defined below will have their keys formatted according to
    | the case setting. If your custom permissions come from external sources
    | (e.g. Terraform, Keycloak) and must remain unchanged, set this to false.
    | When using the separator in custom permission definitions, each segment
    | will be formatted independently (e.g. 'view:system_log' with pascal
    | case becomes 'View:SystemLog').
    |
    */

    'permissions' => [
        'separator' => '.',
        'case' => 'snake',
        'generate' => false,
        // ⚠️ FALSE, AND NOT NEGOTIABLE. Our names are dotted and lower-case;
        // formatting them would produce a key no policy, route or test knows.
        'format_custom_permission_keys' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Shield can automatically generate Laravel policies for your resources.
    | Generated policies mirror each model's location: models under
    | app/Models map into the path below (keeping their nesting), models in
    | any other "Models" directory get a sibling "Policies" directory, and
    | vendor models fall back to the path below. When merge is enabled, the
    | methods below will be combined with any resource-specific methods you
    | define in the resources section.
    |
    */

    'policies' => [
        'path' => app_path('Policies'),
        'merge' => true,
        // Off: every policy in this product is hand-written and carries the
        // reasoning for what it refuses. A generated one would overwrite that.
        'generate' => false,
        'methods' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore',
            'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ],
        'single_parameter_methods' => [
            'viewAny',
            'create',
            'deleteAny',
            'forceDeleteAny',
            'restoreAny',
            'reorder',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Shield supports multiple languages out of the box. When enabled, you
    | can provide translated labels for permissions to create a more
    | localized experience for your international users.
    |
    */

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Here you can fine-tune permissions for specific Filament resources.
    | Use the 'manage' array to override the default policy methods for
    | individual resources, giving you granular control over permissions.
    |
    */

    'resources' => [
        'subject' => 'model',
        'manage' => [
            RoleResource::class => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
            ],
        ],
        'exclude' => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Most Filament pages only require view permissions. Pages listed in the
    | exclude array will be skipped during permission generation and won't
    | appear in your role management interface.
    |
    */

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            Dashboard::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Widgets
    |--------------------------------------------------------------------------
    |
    | Like pages, widgets typically only need view permissions. Add widgets
    | to the exclude array if you don't want them to appear in your role
    | management interface.
    |
    */

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Permissions
    |--------------------------------------------------------------------------
    |
    | Sometimes you need permissions that don't map to resources, pages, or
    | widgets. Define any custom permissions here and they'll be available
    | when editing roles in your application.
    |
    | Keys are formatted per the Permission Builder settings above; set
    | permissions.format_custom_permission_keys to false to use them as-is.
    |
    */

    /*
    | The whole vocabulary of the role screen: every permission a WORKSPACE role
    | may hold, in Arabic.
    |
    | ⚠️ THE PLATFORM'S ARE ABSENT ON PURPOSE. A box that grants
    | `billing.pricing.manage` to a workspace role is a box that makes an owner
    | the platform — `Tenancy\Models\Role` refuses the write in any case, and an
    | always-failing tick box is worse than no tick box. Platform standing is
    | granted by naming a PERSON in `platform_staff`; the permissions behind each
    | platform role come from code.
    */
    'custom_permissions' => PermissionLabels::tenantMap(),

    /*
    |--------------------------------------------------------------------------
    | Entity Discovery
    |--------------------------------------------------------------------------
    |
    | By default, Shield only looks for entities in your default Filament
    | panel. Enable these options if you're using multiple panels and want
    | Shield to discover entities across all of them.
    |
    */

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Policy
    |--------------------------------------------------------------------------
    |
    | Shield can automatically register a policy for role management itself.
    | This lets you control who can manage roles using Laravel's built-in
    | authorization system. Requires a RolePolicy class in your app.
    |
    */

    /*
    | ⚠️ FALSE, AND THE SYMPTOM WAS A 403 ON A SCREEN EVERY UNIT CHECK CALLED
    | OPEN. Shield registers its own role policy when the PANEL boots — so
    | `RoleResource::canViewAny()` answered true in a plain test and the same
    | resource answered 403 inside an HTTP request, where Shield's registration
    | had replaced ours. Its policy asks for permissions in Shield's own
    | generated vocabulary (`View:Role`), which this product does not have and
    | never will, since the generator is off.
    |
    | Ours is registered in `TenancyServiceProvider` and is the only one:
    | `roles.manage`, plus the workspace the row belongs to.
    */
    'register_role_policy' => false,

];
