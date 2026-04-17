# Online Class Booking – WordPress Plugin

A complete WordPress plugin for scheduling online classes via **Zoom**, **Microsoft Teams**, or any custom meeting link. Built with PHP, Bootstrap 5, Font Awesome 6 and FullCalendar 5.

---

## Features

### For Students
- **Monthly / weekly / day calendar** showing available time slots and their booked classes.
- **Book a class** by clicking on an available slot – select tutor, time and optional topic.
- Enforced **24-hour advance booking** rule (configurable in Settings).
- **Cancel a booking** directly from the calendar event.
- Meeting join link displayed after booking and in the class detail view.

### For Teachers
- **Calendar view** displaying both personal availability and scheduled meetings.
- **Add / edit / delete availability slots** – specify date, time range, school and class.
- **Create / edit / delete meetings** – choose platform (Zoom / Teams / Other), set attendees from the class roster, enter topic and duration.
- Meeting links generated automatically via Zoom or Teams API.

### For Admins
- All teacher capabilities plus the ability to act on behalf of any teacher.
- **Teacher filter** dropdown on the calendar to view any teacher's schedule.
- WP Admin sub-menu pages:
  - **Settings** – configure Zoom / Teams API credentials and defaults.
  - **Meetings** – filterable list of all meetings.
  - **Availability** – upcoming availability overview.

---

## Database Design

The plugin reads existing tables:

| Table | Purpose |
|---|---|
| `{prefix}users` | User records |
| `{prefix}usermeta` | User roles (via `{prefix}capabilities` key) |
| `{prefix}bp_groups` | BuddyPress groups (schools & classes) |
| `{prefix}bp_groups_members` | Group membership |
| `{prefix}bp_groups_groupmeta` | Group type (`group_type = school|class`) and parent (`school_id`) |

Custom tables created on plugin activation:

| Table | Purpose |
|---|---|
| `{prefix}oc_availability` | Teacher availability slots |
| `{prefix}oc_meetings` | Meeting records |
| `{prefix}oc_attendees` | Per-meeting attendee list |

> **All database queries use `$wpdb` directly** – no WordPress helper functions (`get_option`, `get_user_meta`, `get_users`, etc.) are used for data access.

---

## BuddyPress Group Setup

Schools and classes are BuddyPress groups typed via `wp_bp_groups_groupmeta`:

```
group_type = school   ← marks a group as a school
group_type = class    ← marks a group as a class
school_id  = <id>     ← links a class to its parent school
```

Assign teachers and students as confirmed members of the appropriate groups.

---

## Shortcodes

Place these shortcodes on any WordPress page:

```
[oc_student_calendar]   → Calendar for students (book / cancel classes)
[oc_teacher_calendar]   → Calendar for teachers & admins (manage availability + meetings)
```

---

## Admin Settings

Go to **Online Class → Settings** to configure:

### Zoom (Server-to-Server OAuth)
1. Create a **Server-to-Server OAuth** app in the [Zoom Marketplace](https://marketplace.zoom.us/).
2. Add the required scopes: `meeting:write:admin`.
3. Enter **Account ID**, **Client ID** and **Client Secret**.

### Microsoft Teams (Graph API)
1. Register an app in [Azure Active Directory](https://portal.azure.com/).
2. Grant `OnlineMeetings.ReadWrite.All` application permission.
3. Enter **Tenant ID**, **Client ID** and **Client Secret**.

### General
| Setting | Default | Description |
|---|---|---|
| Default Meeting Platform | zoom | Used when student books a slot |
| Default Meeting Duration | 60 min | Duration applied to student bookings |
| Advance Booking Hours | 24 h | Minimum hours before class start a student may book |

---

## User Roles

Teachers must have the WordPress role **`teacher`** (or `administrator`). Students are any logged-in users who are not a teacher or admin.

To create a `teacher` role you can add to your theme's `functions.php`:

```php
add_role( 'teacher', 'Teacher', array( 'read' => true ) );
```

---

## File Structure

```
online-class/
├── online-class.php                 Main plugin bootstrap
├── includes/
│   ├── class-db.php                 All $wpdb database operations
│   ├── class-meeting.php            Provider-agnostic meeting manager
│   ├── class-ajax.php               WordPress AJAX action handlers
│   ├── class-shortcodes.php         [oc_student_calendar] / [oc_teacher_calendar]
│   ├── class-admin.php              WP Admin menu & settings page
│   └── providers/
│       ├── class-zoom.php           Zoom Server-to-Server OAuth API
│       └── class-teams.php          MS Teams Graph API
└── assets/
    ├── css/online-class.css         Plugin styles
    └── js/online-class.js           FullCalendar + modal + AJAX logic
```

---

## Third-party Libraries (CDN)

| Library | Version | Purpose |
|---|---|---|
| Bootstrap | 5.3.2 | UI components & grid |
| Font Awesome | 6.5.0 | Icons |
| FullCalendar | 5.11.3 | Interactive calendar |
| jQuery | (WordPress built-in) | DOM & AJAX |
