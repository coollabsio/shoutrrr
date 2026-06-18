import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancel
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
export const cancel = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

cancel.definition = {
    methods: ["delete"],
    url: '/settings/workspace/invitations/{invitation}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancel
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
cancel.url = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { invitation: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { invitation: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            invitation: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        invitation: typeof args.invitation === 'object'
        ? args.invitation.id
        : args.invitation,
    }

    return cancel.definition.url
            .replace('{invitation}', parsedArgs.invitation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancel
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
cancel.delete = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancel
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
const cancelForm = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancel.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancel
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
cancelForm.delete = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancel.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

cancel.form = cancelForm

const invitations = {
    cancel: Object.assign(cancel, cancel),
}

export default invitations