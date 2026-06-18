import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
export const update = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/workspace/members/{membership}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
update.url = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { membership: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { membership: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            membership: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        membership: typeof args.membership === 'object'
        ? args.membership.id
        : args.membership,
    }

    return update.definition.url
            .replace('{membership}', parsedArgs.membership.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
update.patch = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
const updateForm = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
updateForm.patch = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::remove
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
export const remove = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: remove.url(args, options),
    method: 'delete',
})

remove.definition = {
    methods: ["delete"],
    url: '/settings/workspace/members/{membership}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::remove
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
remove.url = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { membership: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { membership: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            membership: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        membership: typeof args.membership === 'object'
        ? args.membership.id
        : args.membership,
    }

    return remove.definition.url
            .replace('{membership}', parsedArgs.membership.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::remove
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
remove.delete = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: remove.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::remove
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
const removeForm = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: remove.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::remove
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
removeForm.delete = (args: { membership: string | number | { id: string | number } } | [membership: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: remove.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

remove.form = removeForm

const members = {
    update: Object.assign(update, update),
    remove: Object.assign(remove, remove),
}

export default members