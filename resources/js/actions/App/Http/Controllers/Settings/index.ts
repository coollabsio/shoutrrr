import ProfileController from './ProfileController'
import WorkspaceSettingsController from './WorkspaceSettingsController'
import ConnectionsController from './ConnectionsController'
import SecurityController from './SecurityController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    WorkspaceSettingsController: Object.assign(WorkspaceSettingsController, WorkspaceSettingsController),
    ConnectionsController: Object.assign(ConnectionsController, ConnectionsController),
    SecurityController: Object.assign(SecurityController, SecurityController),
}

export default Settings