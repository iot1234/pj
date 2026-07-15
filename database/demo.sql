-- OPTIONAL LOCAL-DEVELOPMENT DEMO DATA ONLY.
-- Never import this file into production. Import schema.sql and defaults.sql
-- first. This file deliberately contains no billing settings, integration
-- settings, admin, resident, booking, bill, payment, password, PIN or API key.
-- Room codes and rents are conspicuous test placeholders, not real financial
-- values. Create real rooms from the admin console instead.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

INSERT IGNORE INTO rooms
    (room_code, floor, room_type, monthly_rent, description, amenities, image_key)
VALUES
    ('DEMO-101', 1, 'Demo Standard', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เสื้อผ้า', 'Wi-Fi'),
     'room-standard.jpg'),
    ('DEMO-102', 1, 'Demo Standard', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เสื้อผ้า', 'Wi-Fi'),
     'room-standard.jpg'),
    ('DEMO-103', 1, 'Demo Deluxe', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เย็น', 'ระเบียง', 'Wi-Fi'),
     'room-deluxe.jpg'),
    ('DEMO-201', 2, 'Demo Standard', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เสื้อผ้า', 'Wi-Fi'),
     'room-standard.jpg'),
    ('DEMO-202', 2, 'Demo Deluxe', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เย็น', 'ระเบียง', 'Wi-Fi'),
     'room-deluxe.jpg'),
    ('DEMO-203', 2, 'Demo Studio', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เย็น', 'มุมครัว', 'Wi-Fi'),
     'room-studio.jpg'),
    ('DEMO-301', 3, 'Demo Deluxe', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เย็น', 'ระเบียง', 'Wi-Fi'),
     'room-deluxe.jpg'),
    ('DEMO-302', 3, 'Demo Suite', 1.00,
     'ข้อมูลตัวอย่างสำหรับเครื่องพัฒนาเท่านั้น',
     JSON_ARRAY('เครื่องปรับอากาศ', 'เครื่องทำน้ำอุ่น', 'ตู้เย็น', 'มุมครัว', 'ระเบียง', 'Wi-Fi'),
     'room-suite.jpg');

-- Create the first owner explicitly after local setup. Example:
-- php scripts/create_admin.php --username=owner --role=owner --password-stdin
-- The command must hash the password with password_hash(); never add a
-- plaintext password or reusable password hash to this file.
