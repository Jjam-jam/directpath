#!/usr/bin/env python3
"""
Directpath Facial Recognition System
"""

import sys
import os
import json
import numpy as np
import face_recognition
from datetime import datetime, date, timedelta
import mysql.connector
from mysql.connector import Error

DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'directpath_db'
}

def get_db_connection():
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        return connection
    except Error as e:
        print(f"Database connection error: {e}")
        return None

def load_face_encodings():
    """Load all face encodings from database"""
    connection = get_db_connection()
    if not connection:
        return {}

    encodings = {}
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute("SELECT employee_id, encoding FROM face_encodings")
        rows = cursor.fetchall()
        for row in rows:
            try:
                encoding_list = json.loads(row['encoding'])
                encodings[row['employee_id']] = np.array(encoding_list)
            except (json.JSONDecodeError, ValueError) as e:
                print(f"Error loading encoding for {row['employee_id']}: {e}")
    except Error as e:
        print(f"Error loading encodings: {e}")
    finally:
        connection.close()

    return encodings

def save_face_encoding(employee_id, encoding):
    """Save or update face encoding in database"""
    connection = get_db_connection()
    if not connection:
        return False

    try:
        cursor = connection.cursor()
        encoding_json = json.dumps(encoding.tolist())

        cursor.execute(
            "SELECT id FROM face_encodings WHERE employee_id = %s",
            (employee_id,)
        )
        existing = cursor.fetchone()

        if existing:
            cursor.execute(
                "UPDATE face_encodings SET encoding = %s, updated_at = NOW() WHERE employee_id = %s",
                (encoding_json, employee_id)
            )
        else:
            cursor.execute(
                "INSERT INTO face_encodings (employee_id, encoding) VALUES (%s, %s)",
                (employee_id, encoding_json)
            )

        connection.commit()
        return True
    except Error as e:
        print(f"Error saving encoding: {e}")
        return False
    finally:
        connection.close()

def recognize_face(image_path, known_encodings):
    """Recognize a face from image against all known encodings"""
    try:
        image = face_recognition.load_image_file(image_path)
        face_locations = face_recognition.face_locations(image)
        face_encodings_found = face_recognition.face_encodings(image, face_locations)

        if not face_encodings_found:
            return None, "No face detected in image"

        if len(face_encodings_found) > 1:
            return None, "Multiple faces detected — please ensure only your face is visible"

        face_encoding = face_encodings_found[0]
        known_ids      = list(known_encodings.keys())
        known_encs     = list(known_encodings.values())

        matches        = face_recognition.compare_faces(known_encs, face_encoding, tolerance=0.5)
        face_distances = face_recognition.face_distance(known_encs, face_encoding)

        if True not in matches:
            return None, "Face not recognized"

        best_index = int(np.argmin(face_distances))
        if not matches[best_index]:
            return None, "Face not recognized"

        employee_id = known_ids[best_index]
        confidence  = 1 - face_distances[best_index]
        return employee_id, f"Recognized with {confidence:.2%} confidence"

    except Exception as e:
        return None, f"Error processing image: {str(e)}"

def to_time(val):
    """Convert timedelta or time to datetime.time — MySQL sometimes returns timedelta for TIME columns"""
    if val is None:
        return None
    if isinstance(val, timedelta):
        total_seconds = int(val.total_seconds())
        hours, remainder = divmod(total_seconds, 3600)
        minutes, seconds = divmod(remainder, 60)
        from datetime import time as dt_time
        return dt_time(hours % 24, minutes, seconds)
    return val

def log_attendance(employee_id, action, captured_photo=None, required_work_mode=None):
    """Log attendance time in or out"""
    connection = get_db_connection()
    if not connection:
        return False, "Database connection failed"

    try:
        cursor = connection.cursor()
        if required_work_mode:
            cursor.execute("SELECT work_mode FROM users WHERE employee_id = %s LIMIT 1", (employee_id,))
            user = cursor.fetchone()
            if not user:
                return False, "Employee not found in the database"
            if user[0] != required_work_mode:
                return False, f"Employee {employee_id} is not scheduled for {required_work_mode} work"

        today  = date.today()
        now    = datetime.now().time()

        cursor.execute(
            "SELECT id, time_in, time_out FROM attendance WHERE employee_id = %s AND date = %s",
            (employee_id, today)
        )
        record = cursor.fetchone()

        if action == 'in':
            if record and record[1]:
                return False, "Already timed in today"

            # Calculate late minutes (shift starts 08:00)
            shift_start  = datetime.combine(today, datetime.strptime("08:00:00", "%H:%M:%S").time())
            time_in_dt   = datetime.combine(today, now)
            late_minutes = max(0, int((time_in_dt - shift_start).total_seconds() / 60))

            if record:
                cursor.execute(
                    """UPDATE attendance
                       SET time_in = %s, status = 'present',
                           late_minutes = %s, captured_photo = %s, updated_at = NOW()
                       WHERE id = %s""",
                    (now, late_minutes, captured_photo, record[0])
                )
            else:
                cursor.execute(
                    """INSERT INTO attendance
                       (employee_id, date, time_in, status, late_minutes, captured_photo)
                       VALUES (%s, %s, %s, 'present', %s, %s)""",
                    (employee_id, today, now, late_minutes, captured_photo)
                )

            connection.commit()
            time_str = datetime.combine(today, now).strftime('%I:%M %p')
            late_msg = f" (Late by {late_minutes} min)" if late_minutes > 0 else ""
            return True, f"Time In recorded at {time_str}{late_msg}"

        elif action == 'out':
            if not record or not record[1]:
                return False, "You have not timed in yet"
            if record[2]:
                return False, "Already timed out today"

            # Convert timedelta to time if MySQL returns timedelta
            time_in_val = to_time(record[1])
            if time_in_val is None:
                return False, "Invalid time_in value in database"

            time_in_dt   = datetime.combine(today, time_in_val)
            time_out_dt  = datetime.combine(today, now)
            hours_worked = round((time_out_dt - time_in_dt).total_seconds() / 3600, 2)

            cursor.execute(
                """UPDATE attendance
                   SET time_out = %s, hours_worked = %s, updated_at = NOW()
                   WHERE id = %s""",
                (now, hours_worked, record[0])
            )
            connection.commit()
            time_str = datetime.combine(today, now).strftime('%I:%M %p')
            return True, f"Time Out recorded at {time_str}. Hours worked: {hours_worked}"

        else:
            return False, "Invalid action"

    except Error as e:
        return False, f"Database error: {str(e)}"
    finally:
        connection.close()

def train_face(employee_id, image_path):
    """Train face encoding for an employee"""
    try:
        image = face_recognition.load_image_file(image_path)
        face_locations = face_recognition.face_locations(image)
        face_encodings_found = face_recognition.face_encodings(image, face_locations)

        if not face_encodings_found:
            return False, "No face detected in image"

        if len(face_encodings_found) > 1:
            return False, "Multiple faces detected — use a photo with only the employee"

        encoding = face_encodings_found[0]
        success  = save_face_encoding(employee_id, encoding)

        if success:
            return True, "Face trained successfully"
        else:
            return False, "Failed to save face encoding to database"

    except Exception as e:
        return False, f"Error training face: {str(e)}"

def main():
    if len(sys.argv) < 2:
        print("Usage:")
        print("  python facial_recognition.py train <employee_id> <image_path>")
        print("  python facial_recognition.py recognize <image_path> <action> [employee_id]")
        print("  python facial_recognition.py identify <image_path>")
        sys.exit(1)

    command = sys.argv[1]

    # ── TRAIN ─────────────────────────────────────────────────
    if command == 'train':
        if len(sys.argv) != 4:
            print("Usage: python facial_recognition.py train <employee_id> <image_path>")
            sys.exit(1)

        employee_id = sys.argv[2]
        image_path  = sys.argv[3]

        if not os.path.exists(image_path):
            print(f"Image not found: {image_path}")
            sys.exit(1)

        success, message = train_face(employee_id, image_path)
        print(message)
        sys.exit(0 if success else 1)

    # ── RECOGNIZE (used by face_verify.php) ───────────────────
    elif command == 'recognize':
        if len(sys.argv) < 4:
            print("Usage: python facial_recognition.py recognize <image_path> <action> [employee_id]")
            sys.exit(1)

        image_path  = sys.argv[2]
        action      = sys.argv[3]  # 'in' or 'out'
        expected_id = sys.argv[4] if len(sys.argv) > 4 and sys.argv[4] != '-' else None
        required_work_mode = sys.argv[5] if len(sys.argv) > 5 else None

        if action not in ['in', 'out']:
            print("Action must be 'in' or 'out'")
            sys.exit(1)

        if not os.path.exists(image_path):
            print(f"Image not found: {image_path}")
            sys.exit(1)

        known_encodings = load_face_encodings()
        if not known_encodings:
            print("No trained faces found in database")
            sys.exit(1)

        # Recognize who it is
        employee_id, message = recognize_face(image_path, known_encodings)

        if not employee_id:
            print(f"Recognition failed: {message}")
            sys.exit(1)

        # If expected_id is passed, verify it matches
        if expected_id and employee_id != expected_id:
            print(f"Face does not match the logged-in employee")
            sys.exit(1)

        # Attendance is recorded by PHP (includes/attendance_rules.php) so the time-in
        # window, holidays, late and undertime rules apply. This command no longer writes attendance.
        print("Attendance logging is handled by the PHP rules. Use the identify command.")
        sys.exit(1)

    # ── IDENTIFY ONLY (no attendance logging) ─────────────────
    elif command == 'identify':
        if len(sys.argv) != 3:
            print("Usage: python facial_recognition.py identify <image_path>")
            sys.exit(1)

        image_path = sys.argv[2]

        if not os.path.exists(image_path):
            print(f"Image not found: {image_path}")
            sys.exit(1)

        known_encodings = load_face_encodings()
        if not known_encodings:
            print("No trained faces found")
            sys.exit(1)

        employee_id, message = recognize_face(image_path, known_encodings)
        if employee_id:
            print(f"{employee_id}|{message}")
            sys.exit(0)
        else:
            print(f"Recognition failed: {message}")
            sys.exit(1)

    else:
        print(f"Unknown command: {command}")
        print("Use 'train', 'recognize', or 'identify'")
        sys.exit(1)

if __name__ == "__main__":
    main()