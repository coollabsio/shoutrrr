import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
import workspaceCd3c45 from './workspace'
/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
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
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
workspace.url = (options?: RouteQueryOptions) => {
    return workspace.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
workspace.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: workspace.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
workspace.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: workspace.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
const workspaceForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: workspace.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
* @route '/settings/workspace'
*/
workspaceForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: workspace.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\WorkspaceSettingsController::workspace
* @see app/Http/Controllers/Settings/WorkspaceSettingsController.php:25
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

const settings = {
    workspace: Object.assign(workspace, workspaceCd3c45),
}

export default settings