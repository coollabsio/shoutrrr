import PublicShareController from './PublicShareController'
import DashboardController from './DashboardController'
import NotificationController from './NotificationController'
import WorkspaceController from './WorkspaceController'
import OnboardingController from './OnboardingController'
import Auth from './Auth'
import Settings from './Settings'
import ConnectedAccounts from './ConnectedAccounts'
import AnalyticsController from './AnalyticsController'
import Posts from './Posts'
import AccountSets from './AccountSets'

const Controllers = {
    PublicShareController: Object.assign(PublicShareController, PublicShareController),
    DashboardController: Object.assign(DashboardController, DashboardController),
    NotificationController: Object.assign(NotificationController, NotificationController),
    WorkspaceController: Object.assign(WorkspaceController, WorkspaceController),
    OnboardingController: Object.assign(OnboardingController, OnboardingController),
    Auth: Object.assign(Auth, Auth),
    Settings: Object.assign(Settings, Settings),
    ConnectedAccounts: Object.assign(ConnectedAccounts, ConnectedAccounts),
    AnalyticsController: Object.assign(AnalyticsController, AnalyticsController),
    Posts: Object.assign(Posts, Posts),
    AccountSets: Object.assign(AccountSets, AccountSets),
}

export default Controllers