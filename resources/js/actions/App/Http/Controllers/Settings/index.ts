import ProfileController from './ProfileController'
import WorkspaceSettingsController from './WorkspaceSettingsController'
import PostingScheduleController from './PostingScheduleController'
import ConnectionsController from './ConnectionsController'
import SecurityController from './SecurityController'

const Settings = {
    ProfileController: Object.assign(ProfileController, ProfileController),
    WorkspaceSettingsController: Object.assign(WorkspaceSettingsController, WorkspaceSettingsController),
    PostingScheduleController: Object.assign(PostingScheduleController, PostingScheduleController),
    ConnectionsController: Object.assign(ConnectionsController, ConnectionsController),
    SecurityController: Object.assign(SecurityController, SecurityController),
}

export default Settings