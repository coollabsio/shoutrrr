import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showOverview
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
export const showOverview = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showOverview.url(options),
    method: 'get',
})

showOverview.definition = {
    methods: ["get","head"],
    url: '/settings/workspace',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showOverview
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
showOverview.url = (options?: RouteQueryOptions) => {
    return showOverview.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showOverview
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
showOverview.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showOverview.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showOverview
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
showOverview.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showOverview.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showOverview
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
const showOverviewForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showOverview.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showOverview
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
showOverviewForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showOverview.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showOverview
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
showOverviewForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showOverview.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

showOverview.form = showOverviewForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:50
* @route '/settings/workspace'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/workspace',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:50
* @route '/settings/workspace'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:50
* @route '/settings/workspace'
*/
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:50
* @route '/settings/workspace'
*/
const updateForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::update
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:50
* @route '/settings/workspace'
*/
updateForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateTimezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
export const updateTimezone = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateTimezone.url(options),
    method: 'put',
})

updateTimezone.definition = {
    methods: ["put"],
    url: '/settings/workspace/timezone',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateTimezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
updateTimezone.url = (options?: RouteQueryOptions) => {
    return updateTimezone.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateTimezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
updateTimezone.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateTimezone.url(options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateTimezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
const updateTimezoneForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateTimezone.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateTimezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
updateTimezoneForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateTimezone.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

updateTimezone.form = updateTimezoneForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showMembers
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
export const showMembers = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showMembers.url(options),
    method: 'get',
})

showMembers.definition = {
    methods: ["get","head"],
    url: '/settings/workspace/members',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showMembers
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
showMembers.url = (options?: RouteQueryOptions) => {
    return showMembers.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showMembers
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
showMembers.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showMembers.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showMembers
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
showMembers.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showMembers.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showMembers
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
const showMembersForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showMembers.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showMembers
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
showMembersForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showMembers.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::showMembers
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
showMembersForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: showMembers.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

showMembers.form = showMembersForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::inviteUser
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:110
* @route '/settings/workspace/invite'
*/
export const inviteUser = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: inviteUser.url(options),
    method: 'post',
})

inviteUser.definition = {
    methods: ["post"],
    url: '/settings/workspace/invite',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::inviteUser
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:110
* @route '/settings/workspace/invite'
*/
inviteUser.url = (options?: RouteQueryOptions) => {
    return inviteUser.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::inviteUser
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:110
* @route '/settings/workspace/invite'
*/
inviteUser.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: inviteUser.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::inviteUser
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:110
* @route '/settings/workspace/invite'
*/
const inviteUserForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: inviteUser.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::inviteUser
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:110
* @route '/settings/workspace/invite'
*/
inviteUserForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: inviteUser.url(options),
    method: 'post',
})

inviteUser.form = inviteUserForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateMemberRole
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
export const updateMemberRole = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateMemberRole.url(args, options),
    method: 'patch',
})

updateMemberRole.definition = {
    methods: ["patch"],
    url: '/settings/workspace/members/{membership}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateMemberRole
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
updateMemberRole.url = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
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

    return updateMemberRole.definition.url
            .replace('{membership}', parsedArgs.membership.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateMemberRole
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
updateMemberRole.patch = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateMemberRole.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateMemberRole
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
const updateMemberRoleForm = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMemberRole.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::updateMemberRole
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:144
* @route '/settings/workspace/members/{membership}'
*/
updateMemberRoleForm.patch = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updateMemberRole.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

updateMemberRole.form = updateMemberRoleForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::removeMember
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
export const removeMember = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: removeMember.url(args, options),
    method: 'delete',
})

removeMember.definition = {
    methods: ["delete"],
    url: '/settings/workspace/members/{membership}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::removeMember
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
removeMember.url = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
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

    return removeMember.definition.url
            .replace('{membership}', parsedArgs.membership.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::removeMember
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
removeMember.delete = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: removeMember.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::removeMember
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
const removeMemberForm = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: removeMember.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::removeMember
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:164
* @route '/settings/workspace/members/{membership}'
*/
removeMemberForm.delete = (args: { membership: string | { id: string } } | [membership: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: removeMember.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

removeMember.form = removeMemberForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancelInvitation
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
export const cancelInvitation = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancelInvitation.url(args, options),
    method: 'delete',
})

cancelInvitation.definition = {
    methods: ["delete"],
    url: '/settings/workspace/invitations/{invitation}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancelInvitation
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
cancelInvitation.url = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
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

    return cancelInvitation.definition.url
            .replace('{invitation}', parsedArgs.invitation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancelInvitation
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
cancelInvitation.delete = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancelInvitation.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancelInvitation
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
const cancelInvitationForm = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancelInvitation.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::cancelInvitation
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:191
* @route '/settings/workspace/invitations/{invitation}'
*/
cancelInvitationForm.delete = (args: { invitation: string | { id: string } } | [invitation: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: cancelInvitation.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

cancelInvitation.form = cancelInvitationForm

const WorkspaceSettingsController = { showOverview, update, updateTimezone, showMembers, inviteUser, updateMemberRole, removeMember, cancelInvitation }

export default WorkspaceSettingsController