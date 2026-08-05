import { HugeiconsIcon } from '@hugeicons/react';
import type { HugeiconsProps, IconSvgElement } from '@hugeicons/react';
import {
    Alert02Icon,
    AlertCircleIcon,
    ArchiveIcon,
    ArrowDown01Icon,
    ArrowLeft01Icon,
    ArrowUp01Icon,
    ArrowUpRight01Icon,
    AtSignIcon,
    AttachmentIcon,
    BarChartIcon,
    BellIcon,
    BlocksIcon,
    BookOpen01Icon,
    BookTextIcon,
    Bookmark01Icon,
    BotIcon,
    BubbleChatIcon,
    Building02Icon,
    Calendar01Icon,
    CalendarClockIcon,
    CalendarDaysIcon,
    CalendarXIcon,
    Cancel01Icon,
    ChartColumnIcon,
    CheckCheckIcon,
    CheckIcon,
    CheckListIcon,
    ChevronDownIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ChevronUpIcon,
    CircleCheckIcon,
    CircleIcon,
    ClapperboardIcon,
    Clock01Icon,
    ComputerIcon,
    CopyIcon,
    CreditCardIcon,
    CropIcon,
    CrownIcon,
    Delete02Icon,
    ExternalLinkIcon,
    EyeIcon,
    EyeOffIcon,
    File02Icon,
    Film01Icon,
    FilterIcon,
    Folder01Icon,
    GlobeIcon,
    HeartIcon,
    Home01Icon,
    Image01Icon,
    ImagePlayIcon,
    InboxIcon,
    InformationCircleIcon,
    Key01Icon,
    Layers01Icon,
    LayoutGridIcon,
    Loading03Icon,
    LockIcon,
    Logout01Icon,
    MagicWand01Icon,
    Mail01Icon,
    Menu01Icon,
    Message01Icon,
    Message02Icon,
    MessageAdd01Icon,
    MinusSignIcon,
    MoonIcon,
    MoreHorizontalIcon,
    MoreVerticalIcon,
    MusicNote01Icon,
    OctagonXIcon,
    PanelLeftIcon,
    PauseCircleIcon,
    PauseIcon,
    PencilEdit01Icon,
    PencilIcon,
    PinIcon,
    PlayIcon,
    Plug01Icon,
    PlusSignIcon,
    RadioIcon,
    RefreshIcon,
    RepeatIcon,
    RocketIcon,
    RotateClockwiseIcon,
    ScanIcon,
    Search01Icon,
    SearchRemoveIcon,
    SecurityCheckIcon,
    SentIcon,
    Settings01Icon,
    Share01Icon,
    Shield01Icon,
    ShuffleIcon,
    SmileIcon,
    SplitIcon,
    StarIcon,
    Sun01Icon,
    ThumbsUpIcon,
    TrendingUpDownIcon,
    UnfoldMoreIcon,
    UserAdd01Icon,
    UserGroupIcon,
    UserIcon,
    VolumeHighIcon,
    VolumeLowIcon,
    VolumeMute01Icon,
    Wrench01Icon,
    ZapIcon,
} from '@hugeicons/core-free-icons';
import type { ComponentType } from 'react';

export type IconComponent = ComponentType<HugeiconsProps>;

function icon(data: IconSvgElement): IconComponent {
    return function Icon(props: HugeiconsProps) {
        return <HugeiconsIcon icon={data} {...props} />;
    };
}

export const AlertCircle = icon(AlertCircleIcon);
export const AlertTriangle = icon(Alert02Icon);
export const Archive = icon(ArchiveIcon);
export const ArrowDown = icon(ArrowDown01Icon);
export const ArrowLeft = icon(ArrowLeft01Icon);
export const ArrowUp = icon(ArrowUp01Icon);
export const ArrowUpRight = icon(ArrowUpRight01Icon);
export const AtSign = icon(AtSignIcon);
export const BarChart3 = icon(BarChartIcon);
export const Bell = icon(BellIcon);
export const Blocks = icon(BlocksIcon);
export const BookOpen = icon(BookOpen01Icon);
export const BookText = icon(BookTextIcon);
export const Bookmark = icon(Bookmark01Icon);
export const Bot = icon(BotIcon);
export const Building2 = icon(Building02Icon);
export const Calendar = icon(Calendar01Icon);
export const CalendarClock = icon(CalendarClockIcon);
export const CalendarDays = icon(CalendarDaysIcon);
export const CalendarX = icon(CalendarXIcon);
export const ChartColumn = icon(ChartColumnIcon);
export const Check = icon(CheckIcon);
export const CheckCheck = icon(CheckCheckIcon);
export const CheckCircle2 = icon(CircleCheckIcon);
export const ChevronDown = icon(ChevronDownIcon);
export const ChevronLeft = icon(ChevronLeftIcon);
export const ChevronRight = icon(ChevronRightIcon);
export const ChevronUp = icon(ChevronUpIcon);
export const ChevronsUpDown = icon(UnfoldMoreIcon);
export const Circle = icon(CircleIcon);
export const CircleAlert = icon(AlertCircleIcon);
export const CircleCheck = icon(CircleCheckIcon);
export const Clapperboard = icon(ClapperboardIcon);
export const Clock = icon(Clock01Icon);
export const Copy = icon(CopyIcon);
export const CreditCard = icon(CreditCardIcon);
export const Crop = icon(CropIcon);
export const Crown = icon(CrownIcon);
export const ExternalLink = icon(ExternalLinkIcon);
export const Eye = icon(EyeIcon);
export const EyeOff = icon(EyeOffIcon);
export const FileText = icon(File02Icon);
export const Film = icon(Film01Icon);
export const Filter = icon(FilterIcon);
export const Folder = icon(Folder01Icon);
export const Globe = icon(GlobeIcon);
export const Heart = icon(HeartIcon);
export const Home = icon(Home01Icon);
export const Image = icon(Image01Icon);
export const ImagePlay = icon(ImagePlayIcon);
export const Inbox = icon(InboxIcon);
export const Info = icon(InformationCircleIcon);
export const KeyRound = icon(Key01Icon);
export const Layers = icon(Layers01Icon);
export const LayoutGrid = icon(LayoutGridIcon);
export const ListChecks = icon(CheckListIcon);
export const Loader2 = icon(Loading03Icon);
export const LoaderCircle = icon(Loading03Icon);
export const LockKeyhole = icon(LockIcon);
export const LogOut = icon(Logout01Icon);
export const Mail = icon(Mail01Icon);
export const Menu = icon(Menu01Icon);
export const MessageCircle = icon(Message01Icon);
export const MessageSquare = icon(BubbleChatIcon);
export const MessageSquarePlus = icon(MessageAdd01Icon);
export const MessagesSquare = icon(Message02Icon);
export const Minus = icon(MinusSignIcon);
export const Monitor = icon(ComputerIcon);
export const Moon = icon(MoonIcon);
export const MoreHorizontal = icon(MoreHorizontalIcon);
export const MoreVertical = icon(MoreVerticalIcon);
export const Music2 = icon(MusicNote01Icon);
export const OctagonX = icon(OctagonXIcon);
export const PanelLeft = icon(PanelLeftIcon);
export const Paperclip = icon(AttachmentIcon);
export const Pause = icon(PauseIcon);
export const PauseCircle = icon(PauseCircleIcon);
export const PenLine = icon(PencilEdit01Icon);
export const Pencil = icon(PencilIcon);
export const Pin = icon(PinIcon);
export const Play = icon(PlayIcon);
export const Plug = icon(Plug01Icon);
export const Plus = icon(PlusSignIcon);
export const Radio = icon(RadioIcon);
export const RefreshCw = icon(RefreshIcon);
export const Repeat2 = icon(RepeatIcon);
export const Rocket = icon(RocketIcon);
export const RotateCw = icon(RotateClockwiseIcon);
export const ScanLine = icon(ScanIcon);
export const Search = icon(Search01Icon);
export const SearchX = icon(SearchRemoveIcon);
export const Send = icon(SentIcon);
export const Settings = icon(Settings01Icon);
export const Share2 = icon(Share01Icon);
export const Shield = icon(Shield01Icon);
export const ShieldCheck = icon(SecurityCheckIcon);
export const Shuffle = icon(ShuffleIcon);
export const Smile = icon(SmileIcon);
export const Split = icon(SplitIcon);
export const Star = icon(StarIcon);
export const Sun = icon(Sun01Icon);
export const ThumbsUp = icon(ThumbsUpIcon);
export const Trash2 = icon(Delete02Icon);
export const TrendingUp = icon(TrendingUpDownIcon);
export const TriangleAlert = icon(Alert02Icon);
export const User = icon(UserIcon);
export const UserPlus = icon(UserAdd01Icon);
export const Users = icon(UserGroupIcon);
export const Volume1 = icon(VolumeLowIcon);
export const Volume2 = icon(VolumeHighIcon);
export const VolumeX = icon(VolumeMute01Icon);
export const Wand2 = icon(MagicWand01Icon);
export const Wrench = icon(Wrench01Icon);
export const X = icon(Cancel01Icon);
export const Zap = icon(ZapIcon);
