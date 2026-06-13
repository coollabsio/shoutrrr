import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
import workspaceCd3c45 from './workspace'
import postingSchedule37648c from './posting-schedule'
/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:23
* @route '/settings/workspace'
*/
export const workspace = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspace.url(options),
    method: 'get',
})

workspace.definition = {
    methods: ["get","head"],
    url: '/settings/workspace',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:23
* @route '/settings/workspace'
*/
workspace.url = (options?: RouteQueryOptions) => {
    return workspace.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:23
* @route '/settings/workspace'
*/
workspace.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspace.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:23
* @route '/settings/workspace'
*/
workspace.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: workspace.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:23
* @route '/settings/workspace'
*/
const workspaceForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: workspace.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:23
* @route '/settings/workspace'
*/
workspaceForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: workspace.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:23
* @route '/settings/workspace'
*/
workspaceForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: workspace.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

workspace.form = workspaceForm

/**
* @see \App\Http\Controllers\Settings\PostingScheduleController::postingSchedule
* @see app/Http/Controllers/Settings/PostingScheduleController.php:20
* @route '/settings/posting-schedule'
*/
export const postingSchedule = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: postingSchedule.url(options),
    method: 'get',
})

postingSchedule.definition = {
    methods: ["get","head"],
    url: '/settings/posting-schedule',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\PostingScheduleController::postingSchedule
* @see app/Http/Controllers/Settings/PostingScheduleController.php:20
* @route '/settings/posting-schedule'
*/
postingSchedule.url = (options?: RouteQueryOptions) => {
    return postingSchedule.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\PostingScheduleController::postingSchedule
* @see app/Http/Controllers/Settings/PostingScheduleController.php:20
* @route '/settings/posting-schedule'
*/
postingSchedule.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: postingSchedule.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\PostingScheduleController::postingSchedule
* @see app/Http/Controllers/Settings/PostingScheduleController.php:20
* @route '/settings/posting-schedule'
*/
postingSchedule.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: postingSchedule.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\PostingScheduleController::postingSchedule
* @see app/Http/Controllers/Settings/PostingScheduleController.php:20
* @route '/settings/posting-schedule'
*/
const postingScheduleForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: postingSchedule.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\PostingScheduleController::postingSchedule
* @see app/Http/Controllers/Settings/PostingScheduleController.php:20
* @route '/settings/posting-schedule'
*/
postingScheduleForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: postingSchedule.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\PostingScheduleController::postingSchedule
* @see app/Http/Controllers/Settings/PostingScheduleController.php:20
* @route '/settings/posting-schedule'
*/
postingScheduleForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: postingSchedule.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

postingSchedule.form = postingScheduleForm

const settings = {
    workspace: Object.assign(workspace, workspaceCd3c45),
    postingSchedule: Object.assign(postingSchedule, postingSchedule37648c),
}

export default settings