# LMS DEMO

LMS DEMO is a local PHP/MySQL learning management system demo. It includes learner login, lesson videos, lesson content, private learner notes, public comments with admin moderation, video progress tracking, resources, and a simple admin CMS.

## Main Features

- Introduction plus 5 lessons
- Local MP4 lesson videos in `videos/`
- Learner profile with total video progress
- Private notes per learner and lesson
- Public lesson comments managed from admin
- Admin CMS for lessons, images, text content, users, resources, comments, and progress
- Completion page with Retake Course option

## Local Setup With Laragon

1. Place the project in:
   `C:\laragon\www\LMS`
2. Create a MySQL database named:
   `lms_db`
3. Open the installer once:
   `http://localhost/LMS/install.php`
4. Open the site:
   `http://localhost/LMS/`

## Demo Logins

Learner:
- Email: `demo@lmsdemo.local`
- Password: `demo12345`

Admin:
- URL: `http://localhost/LMS/admin/`
- Username: `admin`
- Password: `demo12345`


## Lesson Navigation and Progress Tracking

Learners can move through the course using the lesson pathway, side navigation, and previous/next lesson buttons. The system is designed so learners can leave a lesson and return later without losing their place.

When a learner watches a lesson video, the LMS saves the current playback position, total watched time, watched percentage, and completion state. If the learner comes back to the same lesson, the video can resume from the last saved position instead of starting from the beginning.

Progress is shown in two useful ways:

- Each lesson shows its own video watched percentage.
- The Learner Profile shows the total watched percentage across all lesson videos.

This makes the demo easy to explain to buyers: learners know where they are, admins can see engagement, and the course can prove how much of the training has actually been watched.

## Videos

Videos are included in this Git project under `videos/`. Each of the 5 lessons is connected to one MP4 file in the local database.

## Git Notes

This repository should include the app code, database schema/seed files, assets, and videos. Runtime uploads and local test/session files should stay out of Git.

Recommended first publish commands from `C:\laragon\www\LMS`:

```bash
git init
git add .
git commit -m "Initial LMS demo project"
git branch -M main
git remote add origin https://github.com/Nofomsok/LMS.git
git push -u origin main
```

## Deployment Notes

Before using on a public server, update `config.php` database settings, run `install.php` once, verify login, then remove or protect `install.php`.