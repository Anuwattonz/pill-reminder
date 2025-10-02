# 📋 Pill Reminder System - Backend API

ระบบ Backend API สำหรับแอปพลิเคชันแจ้งเตือนการรับประทานยา รองรับการเชื่อมต่อกับ Mobile App และอุปกรณ์ ESP32  
พัฒนาด้วย PHP 8.3.6 และ Composer 2.8.9  

---

## 🌟 คุณสมบัติหลัก

### 🔑 Authentication
- สมัครสมาชิก, เข้าสู่ระบบ, รีเซ็ตรหัสผ่าน
- ใช้ JWT token สำหรับยืนยันตัวตน
- Auto refresh token

### 💊 Medications
- CRUD ข้อมูลยา
- รองรับรูปภาพยา
- กำหนดรูปแบบยาและหน่วยยา
- เชื่อมต่อกับ reminder slot

### ⏰ Reminders
- ตั้งเวลาแจ้งเตือนแต่ละ slot
- กำหนดวันและเวลาการแจ้งเตือน
- อัปเดตสถานะ reminder อัตโนมัติ

### 🤖 ESP32 Integration
- ดึงตารางเวลาแจ้งเตือน
- บันทึกการทานยา (รวมรูปภาพ Base64)
- รองรับ missed dose และ actual time

### 📊 History & Statistics
- ดูประวัติการทานยา
- ดูสรุปสถิติรายสัปดาห์/เดือน
- รองรับ pagination และ summary

### 🔧 Settings
- ตั้งค่า volume, delay, alert offset
- ปรับค่าการแจ้งเตือนเริ่มต้นของแอป

### 🔌 Devices
- เชื่อมต่อเครื่อง ESP32 ด้วย machine_SN

---

## 🚀 เทคโนโลยีที่ใช้
- **PHP 8.3.6**  
- **Composer 2.8.9**  
- **MySQL**  
- **firebase/php-jwt ^6.11** - JWT Authentication  
- **PHPMailer ^6.10** - Email/OTP  

---

## 📁 โครงสร้างโปรเจค
```
pill-reminder/
├── api/
│   ├── index.php                 # ตัวจัดการเส้นทางหลักของ API (Main Router)
│   └── .htaccess                 # กำหนดการเขียน URL ใหม่ (URL Rewriting)
│
├── config/
│   ├── api_headers.php           # กำหนดค่า CORS และ HTTP Headers
│   ├── config_loader.php         # โหลดค่าการตั้งค่า Environment (.env)
│   ├── db_connection.php         # การเชื่อมต่อฐานข้อมูล
│   ├── jwt_handler.php           # จัดการระบบ JWT (สร้าง/ตรวจสอบ Token)
│   └── upload_config.php         # ตั้งค่าการอัปโหลดไฟล์
│
├── endpoints/
│   ├── auth/                     # จัดการระบบ Authentication (Login/Register/OTP)
│   ├── medications/              # จัดการข้อมูลยา (CRUD)
│   ├── reminders/                # จัดการระบบแจ้งเตือนยา
│   ├── history/                  # จัดการประวัติการทานยา และสถิติ
│   ├── settings/                 # จัดการการตั้งค่าของระบบ
│   ├── devices/                  # จัดการอุปกรณ์ ESP32/เชื่อมต่อเครื่อง
│   ├── dosage-forms/             # รูปแบบยาและหน่วยการให้ยา
│   └── esp32/                    # API สำหรับอุปกรณ์ ESP32 โดยเฉพาะ
│
├── helpers/
│   └── ResponseHelper.php        # ฟังก์ชันช่วยจัดรูปแบบ Response ของ API
│
├── uploads/                      # โฟลเดอร์เก็บไฟล์อัปโหลดจากผู้ใช้
├── pictures/                     # โฟลเดอร์เก็บรูปภาพจาก ESP32
├── vendor/                       # โฟลเดอร์เก็บ Dependencies ของ Composer
├── .env                          # ไฟล์เก็บค่าการตั้งค่า Environment 
├── composer.json
├── composer.lock
└── pill-reminder.sql             # ไฟล์ sql   
```

## การติดตั้ง
1. Clone repository:
```
git clone <your-repo-url>
cd pill-reminder
```
2. ติดตั้ง dependencies
```
composer install
```
3. ตั้งค่า .env 

4. นำไฟล์ sql ที่ให้ไปใช้งาน
---
