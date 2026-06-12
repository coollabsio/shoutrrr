import DashboardController from './DashboardController'
import WorkspaceController from './WorkspaceController'
import Auth from './Auth'
import Settings from './Settings'
import ConnectedAccounts from './ConnectedAccounts'
import Posts from './Posts'
import AccountSets from './AccountSets'

const Controllers = {
    DashboardController: Object.assign(DashboardController, DashboardController),
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
    Auth: Object.assign(Auth, Auth),
    Settings: Object.assign(Settings, Settings),
    ConnectedAccounts: Object.assign(ConnectedAccounts, ConnectedAccounts),
    Posts: Object.assign(Posts, Posts),
    AccountSets: Object.assign(AccountSets, AccountSets),
}

export default Controllers