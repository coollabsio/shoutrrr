import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
import members79483b from './members'
import invitations from './invitations'
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
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::timezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
export const timezone = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: timezone.url(options),
    method: 'put',
})

timezone.definition = {
    methods: ["put"],
    url: '/settings/workspace/timezone',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::timezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
timezone.url = (options?: RouteQueryOptions) => {
    return timezone.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::timezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
timezone.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: timezone.url(options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::timezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
const timezoneForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: timezone.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::timezone
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:62
* @route '/settings/workspace/timezone'
*/
timezoneForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: timezone.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

timezone.form = timezoneForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::members
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
export const members = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: members.url(options),
    method: 'get',
})

members.definition = {
    methods: ["get","head"],
    url: '/settings/workspace/members',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::members
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
members.url = (options?: RouteQueryOptions) => {
    return members.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::members
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
members.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: members.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::members
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
members.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: members.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::members
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
const membersForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: members.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::members
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
membersForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: members.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::members
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:77
* @route '/settings/workspace/members'
*/
membersForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: members.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

members.form = membersForm

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::invite
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:112
* @route '/settings/workspace/invite'
*/
export const invite = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: invite.url(options),
    method: 'post',
})

invite.definition = {
    methods: ["post"],
    url: '/settings/workspace/invite',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::invite
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:112
* @route '/settings/workspace/invite'
*/
invite.url = (options?: RouteQueryOptions) => {
    return invite.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::invite
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:112
* @route '/settings/workspace/invite'
*/
invite.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: invite.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::invite
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:112
* @route '/settings/workspace/invite'
*/
const inviteForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: invite.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::invite
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:112
* @route '/settings/workspace/invite'
*/
inviteForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: invite.url(options),
    method: 'post',
})

invite.form = inviteForm

const workspace = {
    update: Object.assign(update, update),
    timezone: Object.assign(timezone, timezone),
    members: Object.assign(members, members79483b),
    invite: Object.assign(invite, invite),
    invitations: Object.assign(invitations, invitations),
}

export default workspace