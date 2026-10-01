import {
    Ban,
    Calendar,
    CalendarClock,
    CheckCircle2,
    Circle,
    Clock,
    Home,
    Inbox,
    MapPin,
    Package,
    PhoneOff,
    Repeat,
    Truck,
    Undo2,
    UserCheck,
    UserX,
    XCircle,
    AlertTriangle,
} from 'lucide-react';

// Icon picker catalogue (icons only — statuses themselves come from the API).
export const ICONS = {
    inbox: Inbox,
    'user-check': UserCheck,
    truck: Truck,
    'check-circle': CheckCircle2,
    'phone-off': PhoneOff,
    'calendar-clock': CalendarClock,
    'x-circle': XCircle,
    ban: Ban,
    undo: Undo2,
    repeat: Repeat,
    package: Package,
    clock: Clock,
    calendar: Calendar,
    'map-pin': MapPin,
    home: Home,
    'user-x': UserX,
    alert: AlertTriangle,
    circle: Circle,
};

export default function StatusIcon({ name, className = 'h-3.5 w-3.5' }) {
    const Icon = ICONS[name] || null;
    return Icon ? <Icon className={className} /> : null;
}
