import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::showInvitation
* @see app/Http/Controllers/WorkspaceController.php:145
* @route '/invitation/{token}'
*/
export const showInvitation = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showInvitation.url(args, options),
    method: 'get',
})

showInvitation.definition = {
    methods: ["get","head"],
    url: '/invitation/{token}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::showInvitation
* @see app/Http/Controllers/WorkspaceController.php:145
* @route '/invitation/{token}'
*/
showInvitation.url = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { token: args }
    }

    if (Array.isArray(args)) {
        args = {
            token: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        token: args.token,
    }

    return showInvitation.definition.url
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::showInvitation
* @see app/Http/Controllers/WorkspaceController.php:145
* @route '/invitation/{token}'
*/
showInvitation.get = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showInvitation.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::showInvitation
* @see app/Http/Controllers/WorkspaceController.php:145
* @route '/invitation/{token}'
*/
showInvitation.head = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showInvitation.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::showInvitation
* @see app/Http/Controllers/WorkspaceController.php:145
* @route '/invitation/{token}'
*/
const showInvitationForm = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showInvitation.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::showInvitation
* @see app/Http/Controllers/WorkspaceController.php:145
* @route '/invitation/{token}'
*/
showInvitationForm.get = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showInvitation.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::showInvitation
* @see app/Http/Controllers/WorkspaceController.php:145
* @route '/invitation/{token}'
*/
showInvitationForm.head = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showInvitation.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

showInvitation.form = showInvitationForm

/**
* @see \App\Http\Controllers\WorkspaceController::store
* @see app/Http/Controllers/WorkspaceController.php:24
* @route '/workspaces'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/workspaces',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\WorkspaceController::store
* @see app/Http/Controllers/WorkspaceController.php:24
* @route '/workspaces'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::store
* @see app/Http/Controllers/WorkspaceController.php:24
* @route '/workspaces'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::store
* @see app/Http/Controllers/WorkspaceController.php:24
* @route '/workspaces'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::store
* @see app/Http/Controllers/WorkspaceController.php:24
* @route '/workspaces'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\WorkspaceController::switchMethod
* @see app/Http/Controllers/WorkspaceController.php:48
* @route '/workspaces/switch'
*/
export const switchMethod = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: switchMethod.url(options),
    method: 'post',
})

switchMethod.definition = {
    methods: ["post"],
    url: '/workspaces/switch',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\WorkspaceController::switchMethod
* @see app/Http/Controllers/WorkspaceController.php:48
* @route '/workspaces/switch'
*/
switchMethod.url = (options?: RouteQueryOptions) => {
    return switchMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::switchMethod
* @see app/Http/Controllers/WorkspaceController.php:48
* @route '/workspaces/switch'
*/
switchMethod.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: switchMethod.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::switchMethod
* @see app/Http/Controllers/WorkspaceController.php:48
* @route '/workspaces/switch'
*/
const switchMethodForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: switchMethod.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::switchMethod
* @see app/Http/Controllers/WorkspaceController.php:48
* @route '/workspaces/switch'
*/
switchMethodForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: switchMethod.url(options),
    method: 'post',
})

switchMethod.form = switchMethodForm

/**
* @see \App\Http\Controllers\WorkspaceController::leave
* @see app/Http/Controllers/WorkspaceController.php:64
* @route '/workspaces/{workspace}/leave'
*/
export const leave = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: leave.url(args, options),
    method: 'delete',
})

leave.definition = {
    methods: ["delete"],
    url: '/workspaces/{workspace}/leave',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\WorkspaceController::leave
* @see app/Http/Controllers/WorkspaceController.php:64
* @route '/workspaces/{workspace}/leave'
*/
leave.url = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { workspace: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { workspace: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            workspace: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        workspace: typeof args.workspace === 'object'
        ? args.workspace.id
        : args.workspace,
    }

    return leave.definition.url
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::leave
* @see app/Http/Controllers/WorkspaceController.php:64
* @route '/workspaces/{workspace}/leave'
*/
leave.delete = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: leave.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\WorkspaceController::leave
* @see app/Http/Controllers/WorkspaceController.php:64
* @route '/workspaces/{workspace}/leave'
*/
const leaveForm = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: leave.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::leave
* @see app/Http/Controllers/WorkspaceController.php:64
* @route '/workspaces/{workspace}/leave'
*/
leaveForm.delete = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: leave.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

leave.form = leaveForm

/**
* @see \App\Http\Controllers\WorkspaceController::destroy
* @see app/Http/Controllers/WorkspaceController.php:90
* @route '/workspaces/{workspace}'
*/
export const destroy = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/workspaces/{workspace}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\WorkspaceController::destroy
* @see app/Http/Controllers/WorkspaceController.php:90
* @route '/workspaces/{workspace}'
*/
destroy.url = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { workspace: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { workspace: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            workspace: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        workspace: typeof args.workspace === 'object'
        ? args.workspace.id
        : args.workspace,
    }

    return destroy.definition.url
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::destroy
* @see app/Http/Controllers/WorkspaceController.php:90
* @route '/workspaces/{workspace}'
*/
destroy.delete = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\WorkspaceController::destroy
* @see app/Http/Controllers/WorkspaceController.php:90
* @route '/workspaces/{workspace}'
*/
const destroyForm = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::destroy
* @see app/Http/Controllers/WorkspaceController.php:90
* @route '/workspaces/{workspace}'
*/
destroyForm.delete = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

/**
* @see \App\Http\Controllers\WorkspaceController::transferOwnership
* @see app/Http/Controllers/WorkspaceController.php:119
* @route '/workspaces/{workspace}/transfer'
*/
export const transferOwnership = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: transferOwnership.url(args, options),
    method: 'post',
})

transferOwnership.definition = {
    methods: ["post"],
    url: '/workspaces/{workspace}/transfer',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\WorkspaceController::transferOwnership
* @see app/Http/Controllers/WorkspaceController.php:119
* @route '/workspaces/{workspace}/transfer'
*/
transferOwnership.url = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { workspace: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { workspace: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            workspace: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        workspace: typeof args.workspace === 'object'
        ? args.workspace.id
        : args.workspace,
    }

    return transferOwnership.definition.url
            .replace('{workspace}', parsedArgs.workspace.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::transferOwnership
* @see app/Http/Controllers/WorkspaceController.php:119
* @route '/workspaces/{workspace}/transfer'
*/
transferOwnership.post = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: transferOwnership.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::transferOwnership
* @see app/Http/Controllers/WorkspaceController.php:119
* @route '/workspaces/{workspace}/transfer'
*/
const transferOwnershipForm = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: transferOwnership.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::transferOwnership
* @see app/Http/Controllers/WorkspaceController.php:119
* @route '/workspaces/{workspace}/transfer'
*/
transferOwnershipForm.post = (args: { workspace: string | { id: string } } | [workspace: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: transferOwnership.url(args, options),
    method: 'post',
})

transferOwnership.form = transferOwnershipForm

const WorkspaceController = { showInvitation, store, switchMethod, leave, destroy, transferOwnership, switch: switchMethod }

export default WorkspaceController