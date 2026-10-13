# Mobile App Pre-Check-In API Documentation

## Overview

This document describes the API endpoints and authentication techniques required to implement mobile pre-check-in functionality for the BVCMS mobile application. The system allows families to search for their members, select classes/organizations, and complete check-in remotely before arriving at the physical location.

## Base URL

All API endpoints are relative to your BVCMS instance base URL:
```
https://{your-instance}.tpsdb.com/api/
```

## Architecture Overview

The modern API uses Azure Functions endpoints located in the `CmsApi/EndPoints` directory:
- **MobileDevice_Registration** - Handles device registration and passwordless authentication
- **MobileApp_GetOperations** - Mobile app settings and user profile
- **MobileApp_PostOperations** - Mobile app preferences and activities
- **Attendance_CheckIn_GetOperations** - Check-in family lookup and QR codes
- **Attendance_CheckIn_PostOperations** - Pending check-in management

### Legacy API Note
The older API controllers (`MobileAPIv2Controller`, `CheckInAPIv2Controller`) are still available but should be considered deprecated. This documentation focuses on the modern Azure Functions-based API.

### Data Flow for Pre-Check-In

1. **Device Registration** - Register device with unique instance ID
2. **Authentication** - User authenticates using one-time verification code (SMS/Email)
3. **Profile Retrieval** - Retrieve user profile and family information
4. **Pending Check-In Creation** - Store selected meetings/classes
5. **QR Code Generation** - Generate QR code for family
6. **On-Site Completion** - Scan QR code at kiosk to complete check-in

---

## Authentication

### Modern Authentication Flow (Recommended)

The new API uses a passwordless authentication system with one-time verification codes:

#### Flow Overview

```
1. Request Verification Code → 2. Verify Code → 3. Register Device to Person
```

#### Headers Required for All Requests

All authenticated requests require these headers:

```http
Authorization: Bearer {auth-token}
CmsHost: {your-instance}.tpsdb.com
X-Device-Id: {unique-instance-id}
```

### Step 1: Request Verification Code

**POST** `/api/v1/MobileDevice/RequestVerificationCode`

Sends a one-time verification code to the user via SMS or email.

**Headers:**
```http
X-Device-Id: 550e8400-e29b-41d4-a716-446655440000
```

**Request Body:**
```json
{
  "emailAddress": "john.doe@example.com",
  "cellPhone": null,
  "deviceTypeId": 1,
  "appVersion": "2024.1.0",
  "newPerson": false
}
```

**Field Descriptions:**
- `emailAddress` - User's email (required if cellPhone not provided)
- `cellPhone` - User's cell phone number (required if emailAddress not provided)
- `deviceTypeId` - Device type: 1=iOS, 2=Android, 3=Web
- `appVersion` - App version string
- `newPerson` - Set to `true` if creating a new account

**Response:**
```json
{
  "token": "base64-encoded-auth-token",
  "verificationId": "hashed-code",
  "mobileAppDevice": {
    "id": 12345,
    "instanceId": "550e8400-e29b-41d4-a716-446655440000",
    "deviceTypeId": 1,
    "created": "2024-01-14T08:00:00Z",
    "lastSeen": "2024-01-14T08:00:00Z"
  }
}
```

**Success:** HTTP 200 with auth token
**Errors:**
- HTTP 400 - Missing or invalid request body
- HTTP 404 - No user found with that email/phone
- HTTP 401 - Invalid device ID

### Step 2: Verify Code

**POST** `/api/v1/MobileDevice/VerifyCode`

Verifies the one-time code sent to the user.

**Headers:**
```http
Authorization: Bearer {token-from-step-1}
X-Device-Id: 550e8400-e29b-41d4-a716-446655440000
```

**Request Body:**
```json
{
  "verificationCode": "123456",
  "emailAddress": "john.doe@example.com",
  "cellPhone": null
}
```

**Response:**
```json
{
  "people": [
    {
      "peopleId": 12345,
      "userId": 67890,
      "firstName": "John",
      "lastName": "Doe",
      "emailAddress": "john.doe@example.com",
      "cellPhone": "555-123-4567"
    }
  ]
}
```

**Success:** HTTP 200 with list of matching people
**Errors:**
- HTTP 400 - Invalid request
- HTTP 401 - Invalid or expired verification code

### Step 3: Register Device to Person

**POST** `/api/v1/MobileDevice/RegisterDeviceToPerson`

Associates the device with a specific person/user account.

**Headers:**
```http
Authorization: Bearer {token-from-step-1}
X-Device-Id: 550e8400-e29b-41d4-a716-446655440000
```

**Request Body:**
```json
{
  "peopleId": 12345,
  "userId": 67890,
  "emailAddress": "john.doe@example.com",
  "cellPhone": null,
  "fcmToken": "firebase-cloud-messaging-token"
}
```

**Response:**
```json
{
  "id": 12345,
  "instanceId": "550e8400-e29b-41d4-a716-446655440000",
  "deviceTypeId": 1,
  "peopleId": 12345,
  "userId": 67890,
  "created": "2024-01-14T08:00:00Z",
  "lastSeen": "2024-01-14T08:00:00Z",
  "appVersion": "2024.1.0",
  "authentication": "hashed-auth-token"
}
```

**Success:** HTTP 200 with registered device details
**Errors:**
- HTTP 400 - Email/phone doesn't match verification request
- HTTP 401 - Invalid token

### Optional: PIN Registration

After authentication, users can optionally set up a PIN for faster login.

**POST** `/api/v1/MobileDevice/RegisterPin`

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: 550e8400-e29b-41d4-a716-446655440000
```

**Request Body:**
```json
{
  "pin": "1234"
}
```

**Response:**
```json
{
  "id": 12345,
  "instanceId": "550e8400-e29b-41d4-a716-446655440000",
  "authentication": "new-hashed-auth-token"
}
```

### Legacy Authentication Methods

For backward compatibility, these authentication methods are still supported:

#### PIN Authentication (Legacy)
**Header Format:** `Authorization: PIN {base64-encoded-credentials}`
- Encode `username:pin` in Base64
- Requires device to be registered with `instanceID`

#### Basic Authentication (Legacy)
**Header Format:** `Authorization: Basic {base64-encoded-credentials}`
- Encode `username:password` in Base64
- Username can be actual username, email, or secondary email

---

## API Endpoints

### 1. Mobile App Settings & Profile

#### GET `/api/v1/Mobile/Settings/Login`

Retrieves public settings needed for the login screen (no authentication required).

**Response:**
```json
[
  {
    "id": "MobileBrandColorPrimary",
    "setting": "#0066CC"
  },
  {
    "id": "MobileBrandColorPalette",
    "setting": "{...palette-json...}"
  },
  {
    "id": "MobileNewPersonFieldRequirement",
    "setting": "[{\"field\":\"Email\",\"required\":true}]"
  }
]
```

#### GET `/api/v1/Mobile/Settings`

Retrieves all mobile app settings (requires authentication).

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
```

**Response:**
```json
[
  {
    "id": "Setting1",
    "setting": "value1"
  }
]
```

#### GET `/api/v1/Mobile/MobileUserProfile`

Retrieves the authenticated user's profile including family members.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
```

**Response:**
```json
{
  "peopleId": 12345,
  "userId": 67890,
  "firstName": "John",
  "lastName": "Doe",
  "email": "john.doe@example.com",
  "cellPhone": "555-123-4567",
  "familyId": 123,
  "family": {
    "familyId": 123,
    "headOfHouseholdId": 12345,
    "members": [
      {
        "peopleId": 456,
        "firstName": "Jane",
        "lastName": "Doe",
        "positionInFamily": "Wife"
      },
      {
        "peopleId": 789,
        "firstName": "Jimmy",
        "lastName": "Doe",
        "positionInFamily": "Child"
      }
    ]
  },
  "roles": ["Member", "Checkin"]
}
```

---

### 2. Check-In Family & Pending Check-Ins

#### GET `/api/v1/CheckIn/Family/{familyId}`

Retrieves check-in information for a family including available organizations.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
```

**Path Parameters:**
- `familyId` (int) - The family ID to retrieve

**Response:**
```json
{
  "familyId": 123,
  "familyName": "Doe Family",
  "members": [
    {
      "peopleId": 456,
      "firstName": "Jimmy",
      "lastName": "Doe",
      "age": 8,
      "birthday": "2016-03-15T00:00:00",
      "genderId": 1,
      "gradeLevelId": 203,
      "picture": "https://...",
      "emergencyContact": "Jane Doe",
      "emergencyPhone": "555-123-4567",
      "availableMeetings": [
        {
          "organizationId": 1001,
          "organizationName": "2nd Grade Sunday School",
          "meetingId": 5001,
          "meetingDate": "2024-01-14T09:00:00",
          "location": "Room 201",
          "capacity": 15,
          "numPresent": 8,
          "isFull": false,
          "isClosed": false,
          "isMember": true,
          "isLeader": false,
          "hasCheckedIn": false,
          "earlyCheckinMinutes": 60,
          "lateCheckinMinutes": 60
        }
      ]
    }
  ]
}
```

#### GET `/api/v1/CheckIn/Family/{familyId}/PendingCheckIn`

Retrieves pending check-ins for a family.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
```

**Response:**
```json
[
  {
    "familyId": 123,
    "peopleId": 456,
    "organizationId": 1001,
    "meetingDate": "2024-01-14T09:00:00",
    "present": true,
    "stamp": "2024-01-14T08:30:00"
  }
]
```

#### POST `/api/v1/CheckIn/Family/{familyId}/PendingCheckIn`

Adds or updates a single pending check-in for a family member.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
Content-Type: application/json
```

**Request Body:**
```json
{
  "familyId": 123,
  "peopleId": 456,
  "organizationId": 1001,
  "meetingDate": "2024-01-14T09:00:00",
  "present": true,
  "stamp": "2024-01-14T08:30:00"
}
```

**Response:**
```json
{
  "familyId": 123,
  "peopleId": 456,
  "organizationId": 1001,
  "meetingDate": "2024-01-14T09:00:00",
  "present": true,
  "stamp": "2024-01-14T08:30:00"
}
```

**Success:** HTTP 200
**Errors:**
- HTTP 400 - Invalid request body
- HTTP 403 - User doesn't have permission to check in this family

#### POST `/api/v1/CheckIn/Family/{familyId}/PendingCheckIn/Replace`

Replaces all pending check-ins for a family with a new set.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
Content-Type: application/json
```

**Request Body:**
```json
[
  {
    "familyId": 123,
    "peopleId": 456,
    "organizationId": 1001,
    "meetingDate": "2024-01-14T09:00:00",
    "present": true,
    "stamp": "2024-01-14T08:30:00"
  },
  {
    "familyId": 123,
    "peopleId": 789,
    "organizationId": 1002,
    "meetingDate": "2024-01-14T09:00:00",
    "present": true,
    "stamp": "2024-01-14T08:30:00"
  }
]
```

**Response:** Same as request - the complete list of pending check-ins

**Success:** HTTP 200
**Errors:**
- HTTP 400 - Empty request body
- HTTP 403 - User doesn't have permission

---

### 3. QR Code Operations

#### GET `/api/v1/CheckIn/People/{peopleId}/Qrcode`

Generates a QR code image for a person.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
```

**Path Parameters:**
- `peopleId` (int) - The person ID

**Response:**
- Content-Type: `image/png`
- Body: PNG image bytes

#### GET `/api/v1/CheckIn/People/{peopleId}/Qrcode/Text`

Generates a QR code as a base64-encoded string.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
```

**Response:**
```json
"data:image/png;base64,iVBORw0KGgoAAAANS..."
```

---

### 4. New Person Registration

#### POST `/api/v1/MobileDevice/RegisterDeviceToNewPerson`

Creates a new person/user account and registers the device to them (no prior authentication required).

**Headers:**
```http
X-Device-Id: 550e8400-e29b-41d4-a716-446655440000
```

**Request Body:**
```json
{
  "firstName": "Sarah",
  "lastName": "Doe",
  "emailAddress": "sarah.doe@example.com",
  "cellPhone": null,
  "birthdate": "2018-06-20T00:00:00",
  "campusId": 1,
  "deviceTypeId": 1,
  "appVersion": "2024.1.0",
  "fcmToken": "firebase-token"
}
```

**Response:**
```json
{
  "id": 99999,
  "instanceId": "550e8400-e29b-41d4-a716-446655440000",
  "deviceTypeId": 1,
  "peopleId": 890,
  "userId": 999,
  "created": "2024-01-14T08:00:00Z",
  "authentication": "hashed-auth-token"
}
```

**Success:** HTTP 200
**Errors:**
- HTTP 400 - Missing required fields or email/phone mismatch

---

## Mobile App Implementation Guide

### Step-by-Step Pre-Check-In Flow

#### 1. Initial Setup & Authentication

```
┌─────────────────────────────────────────┐
│ 1. App Launch                           │
├─────────────────────────────────────────┤
│ • Generate unique instance ID (UUID)    │
│ • Store in secure storage               │
│ • Check for saved authentication        │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 2. User Registration/Sign-In            │
├─────────────────────────────────────────┤
│ • Show email/phone input screen         │
│ • POST /api/v1/MobileDevice/            │
│   RequestVerificationCode               │
│ • User enters 6-digit code received     │
│ • POST /api/v1/MobileDevice/VerifyCode  │
│ • Display list of matching people       │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 3. Device Registration                  │
├─────────────────────────────────────────┤
│ • User selects their person record      │
│ • POST /api/v1/MobileDevice/            │
│   RegisterDeviceToPerson                │
│ • Store auth token securely             │
│ • Optional: Register PIN for quick login│
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 4. Load User Profile                    │
├─────────────────────────────────────────┤
│ • GET /api/v1/Mobile/MobileUserProfile  │
│ • Display user info and family members  │
│ • Navigate to main app                  │
└─────────────────────────────────────────┘
```

#### 2. Pre-Check-In Process

```
┌─────────────────────────────────────────┐
│ 1. Navigate to Pre-Check-In             │
├─────────────────────────────────────────┤
│ • User taps "Check-In" or "Pre-Check-In"│
│ • Load family from cached profile       │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 2. Retrieve Family Check-In Info        │
├─────────────────────────────────────────┤
│ • GET /api/v1/CheckIn/Family/{familyId} │
│ • Display family members with photos    │
│ • Show available meetings for each      │
│ • Highlight today's meetings            │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 3. Check for Existing Pending Check-Ins │
├─────────────────────────────────────────┤
│ • GET /api/v1/CheckIn/Family/{familyId}/│
│   PendingCheckIn                        │
│ • If found, pre-select those meetings   │
│ • Show "Edit" mode                      │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 4. Select Meetings                      │
├─────────────────────────────────────────┤
│ • User checks boxes for desired meetings│
│ • Validate meeting times (see rules)    │
│ • Check capacity and availability       │
│ • Update emergency contact if needed    │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 5. Save Pending Check-Ins               │
├─────────────────────────────────────────┤
│ • POST /api/v1/CheckIn/Family/{familyId}│
│   /PendingCheckIn/Replace               │
│ • Show confirmation message             │
│ • Proceed to QR code display            │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 6. Display Family QR Code               │
├─────────────────────────────────────────┤
│ • GET /api/v1/CheckIn/People/{peopleId}/│
│   Qrcode/Text (for head of household)   │
│ • Display large QR code                 │
│ • Show countdown to meeting time        │
│ • Provide "Edit" and "Done" buttons     │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│ 7. On-Site Check-In                     │
├─────────────────────────────────────────┤
│ • Family arrives at location            │
│ • Scan QR code at kiosk                 │
│ • Kiosk processes pending check-ins     │
│ • Labels print automatically            │
│ • Clear pending check-ins from database │
└─────────────────────────────────────────┘
```

#### 3. UI Screens to Implement

**Screen 1: Authentication**
- Email/phone input field
- "Send Code" button
- Code entry screen (6 digits)
- "Verify" button
- Account selection (if multiple matches)

**Screen 2: User Profile**
- Profile photo
- Name and contact info
- Family member cards
- "Pre-Check-In" button prominent
- Settings/logout options

**Screen 3: Pre-Check-In Family View**
- List of family members with photos
- Expandable cards showing available meetings
- Checkbox for each meeting
- Meeting details:
  - Time and location
  - Capacity status
  - "Recommended" badge if applicable
- Emergency contact quick-edit
- "Save" and "Cancel" buttons

**Screen 4: Confirmation**
- Summary of selected meetings
- "Your check-in is ready!" message
- Large QR code for scanning
- Meeting countdown timer
- "Edit Selections" button
- "Done" button

**Screen 5: Settings (Optional)**
- PIN setup/change
- Notification preferences
- Emergency contact defaults
- Logout

### Important Business Rules

#### Meeting Time Validation

```typescript
interface MeetingValidation {
  canCheckIn: boolean;
  reason?: string;
}

function validateMeetingTime(
  meetingDate: Date,
  earlyCheckinMinutes: number,
  lateCheckinMinutes: number
): MeetingValidation {
  const now = new Date();
  const currentMinutes = (now.getHours() * 60) + now.getMinutes();
  const meetingMinutes = (meetingDate.getHours() * 60) + meetingDate.getMinutes();

  // Check if meeting is today
  const isToday = now.toDateString() === meetingDate.toDateString();

  if (!isToday) {
    // Future meetings can be pre-checked in
    return { canCheckIn: true };
  }

  const earliestTime = meetingMinutes - earlyCheckinMinutes;
  const latestTime = meetingMinutes + lateCheckinMinutes;

  if (currentMinutes < earliestTime) {
    return {
      canCheckIn: false,
      reason: `Too early to check in. Check-in opens at ${formatTime(earliestTime)}`
    };
  }

  if (currentMinutes > latestTime) {
    return {
      canCheckIn: false,
      reason: "Check-in window has closed. Please see staff for assistance."
    };
  }

  return { canCheckIn: true };
}
```

#### Capacity Management

```typescript
function checkMeetingCapacity(meeting: Meeting): boolean {
  if (!meeting.capacity) {
    // No capacity limit
    return true;
  }

  if (meeting.numPresent >= meeting.capacity) {
    return false; // Full
  }

  return true;
}

function getMeetingStatus(meeting: Meeting): string {
  if (meeting.isClosed) return "Closed";
  if (meeting.isFull) return "Full";
  if (meeting.hasCheckedIn) return "Checked In";

  const capacity = meeting.capacity || 0;
  const present = meeting.numPresent || 0;
  const remaining = capacity - present;

  if (capacity > 0 && remaining <= 3) {
    return `Only ${remaining} spots left`;
  }

  return "Available";
}
```

#### Membership Validation

```typescript
function canJoinMeeting(
  meeting: Meeting,
  person: Person
): { canJoin: boolean; requiresJoin: boolean; reason?: string } {

  if (meeting.isClosed) {
    return {
      canJoin: false,
      requiresJoin: false,
      reason: "Meeting is closed to new members"
    };
  }

  if (meeting.isFull && !meeting.isMember) {
    return {
      canJoin: false,
      requiresJoin: false,
      reason: "Meeting is at capacity"
    };
  }

  if (!meeting.isMember) {
    return {
      canJoin: true,
      requiresJoin: true,
      reason: "Will be added as member"
    };
  }

  return {
    canJoin: true,
    requiresJoin: false
  };
}
```

### Data Models (TypeScript)

#### Authentication Models

```typescript
interface RequestVerificationRequest {
  emailAddress?: string;
  cellPhone?: string;
  deviceTypeId: number; // 1=iOS, 2=Android, 3=Web
  appVersion: string;
  newPerson: boolean;
}

interface RequestVerificationResponse {
  token: string;
  verificationId: string;
  mobileAppDevice: MobileAppDevice;
}

interface VerifyCodeRequest {
  verificationCode: string;
  emailAddress?: string;
  cellPhone?: string;
}

interface VerifyCodeResponse {
  people: MobileAccount[];
}

interface RegisterDeviceRequest {
  peopleId: number;
  userId: number;
  emailAddress?: string;
  cellPhone?: string;
  fcmToken?: string;
}

interface MobileAppDevice {
  id: number;
  instanceId: string;
  deviceTypeId: number;
  peopleId: number;
  userId: number;
  created: Date;
  lastSeen: Date;
  appVersion: string;
  authentication: string;
}
```

#### Check-In Models

```typescript
interface FamilyCheckIn {
  familyId: number;
  familyName: string;
  members: FamilyMemberCheckIn[];
}

interface FamilyMemberCheckIn {
  peopleId: number;
  firstName: string;
  lastName: string;
  age: number;
  birthday: Date;
  genderId: number;
  gradeLevelId: number;
  picture?: string;
  emergencyContact?: string;
  emergencyPhone?: string;
  availableMeetings: CheckInMeeting[];
}

interface CheckInMeeting {
  organizationId: number;
  organizationName: string;
  meetingId: number;
  meetingDate: Date;
  location: string;
  capacity?: number;
  numPresent?: number;
  isFull: boolean;
  isClosed: boolean;
  isMember: boolean;
  isLeader: boolean;
  hasCheckedIn: boolean;
  earlyCheckinMinutes: number;
  lateCheckinMinutes: number;
}

interface PendingCheckIn {
  familyId: number;
  peopleId: number;
  organizationId: number;
  meetingDate: Date;
  present: boolean;
  stamp: Date;
}
```

### Error Handling

#### HTTP Status Codes

```typescript
enum HttpStatus {
  OK = 200,
  BadRequest = 400,
  Unauthorized = 401,
  Forbidden = 403,
  NotFound = 404,
  InternalServerError = 500
}

interface ApiError {
  status: number;
  message: string;
  details?: any;
}

function handleApiError(error: any): string {
  switch (error.status) {
    case HttpStatus.BadRequest:
      return error.message || "Invalid request. Please check your information.";

    case HttpStatus.Unauthorized:
      return "Authentication failed. Please sign in again.";

    case HttpStatus.Forbidden:
      return "You don't have permission to perform this action.";

    case HttpStatus.NotFound:
      return "The requested information could not be found.";

    case HttpStatus.InternalServerError:
      return "A server error occurred. Please try again later.";

    default:
      return "An unexpected error occurred. Please try again.";
  }
}
```

#### Retry Logic

```typescript
async function retryApiCall<T>(
  apiCall: () => Promise<T>,
  maxRetries: number = 3,
  delayMs: number = 1000
): Promise<T> {
  let lastError: Error;

  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      return await apiCall();
    } catch (error) {
      lastError = error;

      // Don't retry on client errors (4xx)
      if (error.status >= 400 && error.status < 500) {
        throw error;
      }

      // Wait before retrying
      if (attempt < maxRetries - 1) {
        await delay(delayMs * (attempt + 1)); // Exponential backoff
      }
    }
  }

  throw lastError;
}

function delay(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms));
}
```

### Security Considerations

1. **Secure Storage**
   - Store `instanceId` in secure storage (iOS Keychain / Android KeyStore)
   - Store `authToken` in secure storage
   - Never log sensitive data (tokens, PINs, verification codes)
   - Clear all secure data on logout

2. **HTTPS Only**
   - All API calls must use HTTPS
   - Implement certificate pinning for production
   - Validate SSL certificates

3. **Token Management**
   - Include auth token in `Authorization` header
   - Include device ID in `X-Device-Id` header
   - Include host in `CmsHost` header
   - Refresh tokens when needed

4. **Verification Code Best Practices**
   - 6-digit codes
   - Limited validity (typically 10-15 minutes)
   - Rate limiting on code requests
   - Clear visual feedback when code expires

5. **Session Management**
   - Implement inactivity timeout
   - Prompt for re-authentication on sensitive operations
   - Clear pending check-ins after 24 hours (server-side)

### API Request Helper (TypeScript)

```typescript
class ApiClient {
  private baseUrl: string;
  private instanceId: string;
  private authToken: string;

  constructor(host: string, instanceId: string) {
    this.baseUrl = `https://${host}/api`;
    this.instanceId = instanceId;
  }

  setAuthToken(token: string) {
    this.authToken = token;
  }

  private getHeaders(includeAuth: boolean = true): Headers {
    const headers = new Headers();
    headers.append('Content-Type', 'application/json');
    headers.append('CmsHost', this.baseUrl.replace('https://', '').replace('/api', ''));
    headers.append('X-Device-Id', this.instanceId);

    if (includeAuth && this.authToken) {
      headers.append('Authorization', `Bearer ${this.authToken}`);
    }

    return headers;
  }

  async post<T>(endpoint: string, body: any, requiresAuth: boolean = true): Promise<T> {
    const response = await fetch(`${this.baseUrl}${endpoint}`, {
      method: 'POST',
      headers: this.getHeaders(requiresAuth),
      body: JSON.stringify(body)
    });

    if (!response.ok) {
      const error: ApiError = {
        status: response.status,
        message: await response.text()
      };
      throw error;
    }

    return await response.json();
  }

  async get<T>(endpoint: string, requiresAuth: boolean = true): Promise<T> {
    const response = await fetch(`${this.baseUrl}${endpoint}`, {
      method: 'GET',
      headers: this.getHeaders(requiresAuth)
    });

    if (!response.ok) {
      const error: ApiError = {
        status: response.status,
        message: await response.text()
      };
      throw error;
    }

    return await response.json();
  }
}
```

### Testing Checklist

- [ ] Request verification code via SMS
- [ ] Request verification code via email
- [ ] Verify code with valid code
- [ ] Verify code with expired code
- [ ] Register device to person
- [ ] Register PIN for quick login
- [ ] Retrieve user profile
- [ ] Retrieve family check-in data
- [ ] Save pending check-in (single)
- [ ] Replace pending check-ins (multiple)
- [ ] Retrieve pending check-ins
- [ ] Generate QR code for person
- [ ] Meeting time validation (too early)
- [ ] Meeting time validation (too late)
- [ ] Meeting time validation (valid window)
- [ ] Meeting capacity checks
- [ ] Membership validation
- [ ] Emergency contact updates
- [ ] Offline mode handling
- [ ] Network error recovery with retry
- [ ] Session timeout handling
- [ ] Token expiration handling

### Performance Optimization

1. **Caching Strategy**
   ```typescript
   interface CacheEntry<T> {
     data: T;
     timestamp: number;
     expiresIn: number; // milliseconds
   }

   class CacheManager {
     private cache = new Map<string, CacheEntry<any>>();

     set<T>(key: string, data: T, expiresIn: number = 300000) {
       this.cache.set(key, {
         data,
         timestamp: Date.now(),
         expiresIn
       });
     }

     get<T>(key: string): T | null {
       const entry = this.cache.get(key);
       if (!entry) return null;

       const age = Date.now() - entry.timestamp;
       if (age > entry.expiresIn) {
         this.cache.delete(key);
         return null;
       }

       return entry.data as T;
     }
   }
   ```

2. **Batch API Calls**
   - Cache user profile for session
   - Cache family check-in data for 5 minutes
   - Debounce search inputs (500ms minimum)

3. **Image Optimization**
   - Request appropriate QR code sizes
   - Cache QR codes locally
   - Use progressive loading for photos

### Offline Support

Consider implementing offline capabilities for:

1. **Cached Data**
   - User profile
   - Family members
   - Recent pending check-ins

2. **Queue Operations**
   - Store pending check-in changes locally
   - Sync when connection restored
   - Show "offline" indicator

3. **Offline Strategy**
   ```typescript
   async function savePendingCheckIn(checkIn: PendingCheckIn): Promise<void> {
     if (navigator.onLine) {
       // Online - save immediately
       await apiClient.post('/v1/CheckIn/Family/...', checkIn);
     } else {
       // Offline - queue for later
       await offlineQueue.add('savePendingCheckIn', checkIn);
       showOfflineNotification();
     }
   }
   ```

---

## Directory Search

The Directory API allows mobile apps to search for members within organizations, with privacy controls and scoping based on the user's permissions.

### Directory Concepts

**Directory Scopes (Filters):**
- **Organization** - Search within a specific organization the user belongs to
- **All Directories** - Search across all organizations where user is a member and the org has `PublishDirectory` enabled
- **Everyone** - Search the entire people database (requires full directory access role)

**Privacy Levels:**
Directory results return only basic information (name and picture) by default. Email, phone, and address are never included in directory search results for privacy reasons.

### Directory Search Endpoints

#### GET `/api/v1/Directory/People/{peopleId}/SearchFilters`

Retrieves the list of directory scopes available to a person.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
CmsHost: {instance}.tpsdb.com
```

**Path Parameters:**
- `peopleId` (int) - The person ID whose available scopes should be listed

**Response:**
```json
[
  {
    "filterType": 1,
    "organizationId": 101,
    "organizationName": "Youth Group",
    "filterText": "",
    "isOverridden": false,
    "searchCriteria": null,
    "pageNumber": null,
    "pageSize": null
  },
  {
    "filterType": 2,
    "organizationId": -1,
    "organizationName": "All Directories",
    "filterText": "",
    "isOverridden": false,
    "searchCriteria": null,
    "pageNumber": null,
    "pageSize": null
  },
  {
    "filterType": 3,
    "organizationId": -2,
    "organizationName": "Everyone",
    "filterText": "",
    "isOverridden": false,
    "searchCriteria": null,
    "pageNumber": null,
    "pageSize": null
  }
]
```

**Filter Types:**
- `1` - Organization (specific org)
- `2` - All Directories (all publishing orgs)
- `3` - Everyone (requires special permission)

**Success:** HTTP 200 with list of available filters (can be empty)
**Errors:**
- HTTP 403 - User lacks permission

#### POST `/api/v1/Directory/ExecuteSearch`

Executes a directory search with the specified filter.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
CmsHost: {instance}.tpsdb.com
Content-Type: application/json
```

**Request Body:**
```json
{
  "filterType": 1,
  "organizationId": 101,
  "organizationName": "Youth Group",
  "filterText": "smith",
  "isOverridden": false,
  "searchCriteria": null,
  "pageNumber": 0,
  "pageSize": 20
}
```

**Field Descriptions:**
- `filterType` - Type of filter (1=Organization, 2=AllDirectories, 3=Everyone)
- `organizationId` - Organization ID to search (use -1 for AllDirectories, -2 for Everyone)
- `filterText` - Partial name to search for (case-insensitive)
- `pageNumber` - Zero-based page number
- `pageSize` - Results per page (default 20)

**Response:**
```json
{
  "searchResults": [
    {
      "peopleId": 12345,
      "firstName": "John",
      "lastName": "Smith",
      "preferredName": "Johnny",
      "pictureUrl": "https://...",
      "organizationName": "Youth Group"
    },
    {
      "peopleId": 12346,
      "firstName": "Jane",
      "lastName": "Smith",
      "preferredName": "Jane",
      "pictureUrl": "https://...",
      "organizationName": "Youth Group"
    }
  ],
  "pageNumber": 0,
  "pageSize": 20,
  "totalPages": 1,
  "totalRecords": 2,
  "hasPreviousPage": false,
  "hasNextPage": false
}
```

**Success:** HTTP 200 with paged results
**Errors:**
- HTTP 400 - Invalid request body
- HTTP 403 - User requested "Everyone" scope without permission

#### POST `/api/v1/Directory/ExecuteGroupedSearch`

Executes a directory search and groups results by last name initial (A-Z).

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
CmsHost: {instance}.tpsdb.com
Content-Type: application/json
```

**Request Body:** Same as ExecuteSearch

**Response:**
```json
{
  "resultGroups": [
    {
      "groupKey": "S",
      "people": [
        {
          "peopleId": 12345,
          "firstName": "John",
          "lastName": "Smith",
          "preferredName": "Johnny",
          "pictureUrl": "https://..."
        },
        {
          "peopleId": 12346,
          "firstName": "Jane",
          "lastName": "Smith",
          "preferredName": "Jane",
          "pictureUrl": "https://..."
        }
      ]
    }
  ],
  "pageNumber": 0,
  "pageSize": 20,
  "totalPages": 1,
  "totalRecords": 2,
  "hasPreviousPage": false,
  "hasNextPage": false
}
```

**Use Case:** Ideal for A-Z browsing interfaces where users can jump to a letter.

### Directory Privacy Settings

Users can control what information is visible in directory searches on a per-organization basis.

#### GET `/api/v1/Directory/People/{peopleId}/PrivacySettings`

Gets global directory privacy settings for a person.

**Response:**
```json
{
  "peopleId": 12345,
  "restrictions": [
    {
      "fieldName": "Email",
      "isVisible": false
    },
    {
      "fieldName": "Phone",
      "isVisible": true
    },
    {
      "fieldName": "Address",
      "isVisible": false
    }
  ]
}
```

#### GET `/api/v1/Directory/People/{peopleId}/Organizations/{organizationId}/PrivacySettings`

Gets directory privacy settings for a specific organization membership.

**Response:** Same structure as global privacy settings

#### POST `/api/v1/Directory/People/{peopleId}/PrivacySettings`

Updates global directory privacy settings.

**Request Body:**
```json
[
  {
    "fieldName": "Email",
    "isVisible": false
  },
  {
    "fieldName": "Phone",
    "isVisible": true
  }
]
```

**Response:**
```json
{
  "affectedRows": 2,
  "peopleId": 12345
}
```

#### GET `/api/v1/Directory/People/{peopleId}/Organizations`

Gets list of organizations where the person is a member and directory is published.

**Response:**
```json
[
  {
    "filterType": 1,
    "organizationId": 101,
    "organizationName": "Youth Group",
    "isOverridden": false
  },
  {
    "filterType": 1,
    "organizationId": 102,
    "organizationName": "Worship Team",
    "isOverridden": true
  }
]
```

**Note:** `isOverridden` indicates the person has custom privacy settings for that organization.

---

## Conversations (Messaging) - PRE-RELEASE

The Conversations API provides person-to-person and group messaging capabilities. This API is currently in pre-release and subject to change.

### Conversation Concepts

**Conversation Types:**
- **Direct Messages** - 1-on-1 conversation between two people
- **Group Conversations** - Multi-person conversations
- **Organization Conversations** - Conversations tied to an organization membership

**Features:**
- Text messages
- Image/video attachments
- Message reactions (emoji)
- Reply threading
- Message search
- Read/unread tracking
- Silence/mute conversations
- Message reporting and moderation

### Core Conversation Endpoints

#### GET `/api/v1/People/{peopleId}/Conversations`

Retrieves all conversations for a person, split into active and available.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
CmsHost: {instance}.tpsdb.com
```

**Query Parameters:**
- `pageNumber` (int, optional) - Page number (default 0)
- `pageSize` (int, optional) - Page size (default 20)

**Response:**
```json
{
  "peopleId": 12345,
  "activeConversations": {
    "items": [
      {
        "conversationId": 1001,
        "name": "Youth Group Chat",
        "description": "General chat for youth group",
        "coverUrl": "https://...",
        "organizationId": 101,
        "conversationType": 2,
        "createdBy": 12345,
        "createdDate": "2024-01-01T10:00:00Z",
        "isDeleted": false,
        "unreadMessages": true,
        "unreadMessageCount": 5,
        "lastMessage": "See you Sunday!",
        "lastMessageDate": "2024-01-14T09:30:00Z",
        "memberCount": 25,
        "canPostMessages": true,
        "canAttachImages": true,
        "canAttachVideos": true
      }
    ],
    "pageNumber": 0,
    "pageSize": 20,
    "totalPages": 1,
    "totalRecords": 1
  },
  "availableConversations": {
    "items": [],
    "pageNumber": 0,
    "pageSize": 20,
    "totalPages": 0,
    "totalRecords": 0
  }
}
```

**Active vs Available:**
- **Active** - Conversations the user has joined
- **Available** - Conversations the user can join but hasn't yet

#### GET `/api/v1/Conversations/{conversationId}`

Retrieves details for a specific conversation.

**Response:**
```json
{
  "conversationId": 1001,
  "name": "Youth Group Chat",
  "description": "General chat for youth group",
  "coverUrl": "https://...",
  "organizationId": 101,
  "conversationType": 2,
  "createdBy": 12345,
  "createdDate": "2024-01-01T10:00:00Z",
  "isDeleted": false,
  "unreadMessages": true,
  "unreadMessageCount": 5,
  "lastMessage": "See you Sunday!",
  "lastMessageDate": "2024-01-14T09:30:00Z",
  "memberCount": 25,
  "canPostMessages": true,
  "canAttachImages": true,
  "canAttachVideos": true,
  "conversationMembers": null,
  "conversationMessages": null
}
```

#### POST `/api/v1/Conversations`

Creates a new conversation.

**Request Body:**
```json
{
  "name": "Planning Committee",
  "description": "Event planning discussion",
  "coverUrl": null,
  "organizationId": 101,
  "conversationType": 2
}
```

**Conversation Types:**
- `1` - Direct message
- `2` - Group conversation
- `3` - Organization-wide conversation

**Response:**
```json
{
  "conversationId": 1002,
  "name": "Planning Committee",
  "description": "Event planning discussion",
  "conversationType": 2,
  "createdBy": 12345,
  "createdDate": "2024-01-14T10:00:00Z",
  "memberCount": 1,
  "canPostMessages": true,
  "canAttachImages": true,
  "canAttachVideos": true
}
```

#### PUT `/api/v1/Conversations/{conversationId}/Update`

Updates conversation details (name, description, cover image).

**Request Body:**
```json
{
  "conversationId": 1002,
  "name": "Event Planning Team",
  "description": "Updated description",
  "coverUrl": "https://..."
}
```

**Success:** HTTP 200 with updated conversation object

#### DELETE `/api/v1/Conversations/{conversationId}/Delete`

Deletes a conversation (soft delete - marks as deleted).

**Success:** HTTP 200
**Note:** Only the creator or users with appropriate permissions can delete.

### Conversation Membership

#### GET `/api/v1/Conversations/{conversationId}/Members`

Retrieves list of conversation members.

**Query Parameters:**
- `pageNumber` (int, optional)
- `pageSize` (int, optional)

**Response:**
```json
{
  "items": [
    {
      "conversationMemberId": 5001,
      "conversationId": 1001,
      "peopleId": 12345,
      "firstName": "John",
      "lastName": "Smith",
      "pictureUrl": "https://...",
      "joinedDate": "2024-01-01T10:00:00Z",
      "isSilenced": false,
      "isAdmin": true
    }
  ],
  "pageNumber": 0,
  "pageSize": 20,
  "totalPages": 1,
  "totalRecords": 25
}
```

#### POST `/api/v1/Conversations/{conversationId}/Join`

Joins an available conversation.

**Request Body:**
```json
{
  "peopleId": 12345
}
```

**Success:** HTTP 200 with conversation member object

#### POST `/api/v1/Conversations/{conversationId}/Members`

Adds another person to a conversation (requires admin rights).

**Request Body:**
```json
{
  "peopleId": 12346
}
```

#### DELETE `/api/v1/Conversations/{conversationId}/Members/{memberId}/Delete`

Removes a member from the conversation.

**Success:** HTTP 200

#### POST `/api/v1/Conversations/{conversationId}/Silence`

Silences (mutes) a conversation for the current user.

**Request Body:**
```json
{
  "peopleId": 12345
}
```

**Success:** HTTP 200

#### POST `/api/v1/Conversations/{conversationId}/Unsilence`

Un-silences a conversation.

**Request Body:**
```json
{
  "peopleId": 12345
}
```

**Success:** HTTP 200

### Messages

#### GET `/api/v1/Conversations/{conversationId}/Messages`

Retrieves messages for a conversation.

**Query Parameters:**
- `pageNumber` (int, optional)
- `pageSize` (int, optional)
- `includeDeleted` (bool, optional) - Include deleted messages (default false)

**Response:**
```json
{
  "items": [
    {
      "conversationMessageId": 10001,
      "conversationId": 1001,
      "peopleId": 12345,
      "organizationId": 101,
      "messageText": "Looking forward to Sunday!",
      "createdDate": "2024-01-14T09:30:00Z",
      "isDeleted": false,
      "replyMessageId": null,
      "replyToName": null,
      "replyToText": null,
      "people": {
        "peopleId": 12345,
        "firstName": "John",
        "lastName": "Smith",
        "pictureUrl": "https://..."
      },
      "conversationAttachments": [],
      "conversationReactions": [
        {
          "reaction": "👍",
          "count": 3,
          "hasReacted": true
        }
      ]
    }
  ],
  "pageNumber": 0,
  "pageSize": 20,
  "totalPages": 5,
  "totalRecords": 87
}
```

#### POST `/api/v1/Conversations/{conversationId}/Messages`

Posts a new message to a conversation.

**Request Body:**
```json
{
  "peopleId": 12345,
  "messageText": "See you all on Sunday!",
  "replyMessageId": null
}
```

**Response:**
```json
{
  "conversationMessageId": 10002,
  "conversationId": 1001,
  "peopleId": 12345,
  "messageText": "See you all on Sunday!",
  "createdDate": "2024-01-14T10:00:00Z",
  "isDeleted": false,
  "people": {
    "peopleId": 12345,
    "firstName": "John",
    "lastName": "Smith",
    "pictureUrl": "https://..."
  },
  "conversationAttachments": [],
  "conversationReactions": []
}
```

#### POST `/api/v1/Conversations/{conversationId}/Messages/Search`

Searches messages within a conversation.

**Request Body:**
```json
{
  "conversationId": 1001,
  "keyword": "sunday",
  "pageNumber": 0,
  "pageSize": 20
}
```

**Response:** Same structure as GET Messages

#### PUT `/api/v1/Conversations/Messages/{messageId}/Update`

Edits a message (only by original author).

**Request Body:**
```json
{
  "conversationMessageId": 10002,
  "messageText": "See you all on Sunday morning!"
}
```

**Success:** HTTP 200 with updated message

#### DELETE `/api/v1/Conversations/Messages/{messageId}/Delete`

Deletes a message (soft delete).

**Success:** HTTP 200

### Message Attachments

#### POST `/api/v1/Conversations/Messages/{messageId}/Attachments`

Adds an attachment to an existing message.

**Request:**
- Content-Type: `multipart/form-data`
- Form field: file upload

**Response:**
```json
{
  "conversationAttachmentId": 2001,
  "conversationMessageId": 10002,
  "attachmentUrl": "https://...",
  "attachmentType": "image/jpeg",
  "fileName": "photo-20240114-100000.jpg",
  "createdDate": "2024-01-14T10:00:00Z"
}
```

#### POST `/api/v1/Conversations/{conversationId}/MediaMessage`

Creates a new message with an attachment in one operation.

**Request:**
- Content-Type: `multipart/form-data`
- Form fields:
  - `message` (JSON) - ConversationMessageDto
  - `file` - File upload

**Response:** Complete message object with attachment included

#### DELETE `/api/v1/Conversations/Messages/{messageId}/Attachments/{attachmentId}/Delete`

Removes an attachment from a message.

**Success:** HTTP 200

### Message Reactions

#### GET `/api/v1/Conversations/Messages/{messageId}/Reactions`

Gets all reactions for a message.

**Response:**
```json
{
  "reactions": {
    "items": [
      {
        "conversationReactionId": 3001,
        "conversationMessageId": 10001,
        "peopleId": 12346,
        "reaction": "👍",
        "createdDate": "2024-01-14T09:31:00Z",
        "people": {
          "peopleId": 12346,
          "firstName": "Jane",
          "lastName": "Doe",
          "pictureUrl": "https://..."
        }
      }
    ],
    "pageNumber": 0,
    "pageSize": 20,
    "totalRecords": 3
  },
  "messageReactions": [
    {
      "reaction": "👍",
      "count": 3,
      "hasReacted": true
    }
  ]
}
```

#### POST `/api/v1/Conversations/Messages/{messageId}/Reactions`

Adds a reaction to a message.

**Request Body:**
```json
{
  "peopleId": 12345,
  "reaction": "❤️"
}
```

**Response:**
```json
{
  "reaction": {
    "conversationReactionId": 3002,
    "conversationMessageId": 10001,
    "peopleId": 12345,
    "reaction": "❤️",
    "createdDate": "2024-01-14T10:05:00Z"
  },
  "messageReactions": [
    {
      "reaction": "👍",
      "count": 3,
      "hasReacted": false
    },
    {
      "reaction": "❤️",
      "count": 1,
      "hasReacted": true
    }
  ]
}
```

#### DELETE `/api/v1/Conversations/Messages/{messageId}/Reactions/{reactionId}/Delete`

Removes a reaction from a message.

**Success:** HTTP 200

### Message Reporting & Moderation

#### POST `/api/v1/Conversations/Messages/{messageId}/Report`

Reports a message for moderation review.

**Success:** HTTP 200 with report object

**Response:**
```json
{
  "reportId": 4001,
  "conversationMessageId": 10001,
  "reportedBy": 12345,
  "reportedDate": "2024-01-14T10:10:00Z",
  "reason": "Inappropriate content"
}
```

#### POST `/api/v1/Conversations/Messages/{messageId}/Report/Clear`

Clears all reports from a message (moderator action).

**Success:** HTTP 200

#### POST `/api/v1/Conversations/Messages/Report/{reportId}/Clear`

Clears a specific report (moderator action).

**Success:** HTTP 200

### Data Models (TypeScript)

#### Conversation Models

```typescript
interface ConversationDto {
  conversationId?: number;
  name: string;
  description?: string;
  coverUrl?: string;
  organizationId?: number;
  conversationType?: number; // 1=Direct, 2=Group, 3=Organization
  createdBy?: number;
  createdDate?: Date;
  deletedBy?: number;
  deletedDate?: Date;
  isDeleted?: boolean;
  unreadMessages: boolean;
  unreadMessageCount?: number;
  lastMessage?: string;
  lastMessageDate?: Date;
  memberCount?: number;
  canPostMessages: boolean;
  canAttachImages: boolean;
  canAttachVideos: boolean;
  conversationMembers?: PagedList<ConversationMemberDto>;
  conversationMessages?: PagedList<ConversationMessageDto>;
}

interface ConversationMessageDto {
  conversationMessageId: number;
  conversationId: number;
  peopleId: number;
  organizationId?: number;
  messageText: string;
  createdDate?: Date;
  deletedBy?: number;
  deletedDate?: Date;
  isDeleted: boolean;
  replyMessageId?: number;
  replyToName?: string;
  replyToText?: string;
  people?: ConversationPersonDto;
  conversation?: ConversationDto;
  conversationAttachments?: ConversationAttachmentDto[];
  conversationReactions?: ConversationReactionSummaryDto[];
}

interface ConversationMemberDto {
  conversationMemberId: number;
  conversationId: number;
  peopleId: number;
  firstName?: string;
  lastName?: string;
  pictureUrl?: string;
  joinedDate?: Date;
  isSilenced: boolean;
  isAdmin: boolean;
}

interface ConversationAttachmentDto {
  conversationAttachmentId: number;
  conversationMessageId: number;
  attachmentUrl: string;
  attachmentType: string;
  fileName: string;
  createdDate?: Date;
}

interface ConversationReactionDto {
  conversationReactionId: number;
  conversationMessageId: number;
  peopleId: number;
  reaction: string; // Emoji
  createdDate?: Date;
  people?: ConversationPersonDto;
}

interface ConversationReactionSummaryDto {
  reaction: string;
  count: number;
  hasReacted: boolean;
}
```

#### Directory Models

```typescript
interface DirectorySearchFilterDto {
  filterType?: number; // 1=Organization, 2=AllDirectories, 3=Everyone
  organizationId?: number;
  organizationName?: string;
  filterText?: string;
  isOverridden: boolean;
  searchCriteria?: any[];
  pageNumber?: number;
  pageSize?: number;
}

interface DirectorySearchResult {
  peopleId: number;
  firstName: string;
  lastName: string;
  preferredName?: string;
  pictureUrl?: string;
  organizationName?: string;
}

interface DirectorySearchResults {
  searchResults: DirectorySearchResult[];
  pageNumber: number;
  pageSize: number;
  totalPages: number;
  totalRecords: number;
  hasPreviousPage: boolean;
  hasNextPage: boolean;
}

interface DirectorySearchGroupedResults {
  resultGroups: DirectoryResultGroup[];
  pageNumber: number;
  pageSize: number;
  totalPages: number;
  totalRecords: number;
  hasPreviousPage: boolean;
  hasNextPage: boolean;
}

interface DirectoryResultGroup {
  groupKey: string; // Letter (A-Z)
  people: DirectorySearchResult[];
}
```

### Implementation Guidelines

#### Directory Search Implementation

```typescript
// 1. Get available scopes on app launch
async function loadDirectoryScopes(peopleId: number): Promise<DirectorySearchFilterDto[]> {
  const scopes = await apiClient.get<DirectorySearchFilterDto[]>(
    `/v1/Directory/People/${peopleId}/SearchFilters`
  );

  // Cache these for the session
  localStorage.setItem('directoryScopes', JSON.stringify(scopes));
  return scopes;
}

// 2. Execute search with debouncing
const debouncedSearch = debounce(async (filterText: string, filter: DirectorySearchFilterDto) => {
  const searchRequest = {
    ...filter,
    filterText,
    pageNumber: 0,
    pageSize: 20
  };

  const results = await apiClient.post<DirectorySearchResults>(
    '/v1/Directory/ExecuteSearch',
    searchRequest
  );

  displayResults(results);
}, 500);

// 3. Handle scope selection
function onScopeChange(selectedScope: DirectorySearchFilterDto) {
  currentScope = selectedScope;
  debouncedSearch(searchText, currentScope);
}
```

#### Conversations Implementation

```typescript
// 1. Load conversations on app open
async function loadConversations(peopleId: number) {
  const response = await apiClient.get<PersonConversationsResponse>(
    `/v1/People/${peopleId}/Conversations?pageNumber=0&pageSize=50`
  );

  // Separate active and available
  displayActiveConversations(response.activeConversations.items);
  displayAvailableConversations(response.availableConversations.items);

  // Show unread badges
  const unreadCount = response.activeConversations.items
    .reduce((sum, conv) => sum + (conv.unreadMessageCount || 0), 0);
  updateUnreadBadge(unreadCount);
}

// 2. Load messages for a conversation
async function loadMessages(conversationId: number, pageNumber: number = 0) {
  const messages = await apiClient.get<PagedList<ConversationMessageDto>>(
    `/v1/Conversations/${conversationId}/Messages?pageNumber=${pageNumber}&pageSize=50`
  );

  displayMessages(messages.items.reverse()); // Newest at bottom

  // Implement infinite scroll
  if (messages.hasNextPage) {
    setupInfiniteScroll(() => loadMessages(conversationId, pageNumber + 1));
  }
}

// 3. Send a message
async function sendMessage(conversationId: number, text: string, replyTo?: number) {
  const message: ConversationMessageDto = {
    conversationId,
    peopleId: currentUser.peopleId,
    messageText: text,
    replyMessageId: replyTo
  };

  const sent = await apiClient.post<ConversationMessageDto>(
    `/v1/Conversations/${conversationId}/Messages`,
    message
  );

  appendMessageToUI(sent);
  scrollToBottom();
}

// 4. Send message with attachment
async function sendMediaMessage(conversationId: number, text: string, file: File) {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('message', JSON.stringify({
    conversationId,
    peopleId: currentUser.peopleId,
    messageText: text
  }));

  const response = await fetch(
    `${apiBase}/v1/Conversations/${conversationId}/MediaMessage`,
    {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authToken}`,
        'X-Device-Id': instanceId,
        'CmsHost': cmsHost
      },
      body: formData
    }
  );

  const message = await response.json();
  appendMessageToUI(message);
}

// 5. Add reaction to message
async function toggleReaction(messageId: number, emoji: string, hasReacted: boolean) {
  if (hasReacted) {
    // Find and remove existing reaction
    const reaction = findUserReaction(messageId, emoji);
    if (reaction) {
      await apiClient.delete(
        `/v1/Conversations/Messages/${messageId}/Reactions/${reaction.conversationReactionId}/Delete`
      );
    }
  } else {
    // Add new reaction
    await apiClient.post(
      `/v1/Conversations/Messages/${messageId}/Reactions`,
      {
        peopleId: currentUser.peopleId,
        reaction: emoji
      }
    );
  }

  // Refresh reaction summary
  await refreshMessageReactions(messageId);
}

// 6. Search messages
async function searchMessages(conversationId: number, keyword: string) {
  const results = await apiClient.post<PagedList<ConversationMessageDto>>(
    `/v1/Conversations/${conversationId}/Messages/Search`,
    {
      conversationId,
      keyword,
      pageNumber: 0,
      pageSize: 20
    }
  );

  displaySearchResults(results.items);
}
```

### Best Practices

**Directory Search:**
1. Cache available scopes for the session
2. Implement 500ms debounce on search input
3. Show "Everyone" option only if user has permission
4. Display organization names with each result
5. Respect privacy - never request or display email/phone

**Conversations:**
1. Poll for new messages every 30 seconds or use SignalR/WebSockets if available
2. Implement optimistic UI updates for sending messages
3. Cache conversation lists for offline viewing
4. Implement infinite scroll for long message threads
5. Show typing indicators if supported
6. Handle image/video uploads with progress bars
7. Implement message retry on failure
8. Show clear "silenced" indicators for muted conversations

---

## Push Notifications & Real-Time Updates

The mobile app supports push notifications via Firebase Cloud Messaging (FCM) and can poll for in-app notifications and alerts.

### Push Notification Architecture

**Components:**
1. **FCM Token Registration** - Each device registers its Firebase Cloud Messaging token with the server
2. **Notification Service** - Server-side service creates notification batches and sends them via Firebase
3. **In-App Notifications** - Three types of in-app notifications: Alerts, UserFeed, and Messages
4. **Polling** - Mobile apps poll for unread notifications and real-time updates

**Notification Types:**
- `1` - **Alert** - Critical alerts requiring immediate attention
- `2` - **UserFeed** - General notifications and activity updates
- `3` - **Messages** - Direct messages and conversation notifications (distinct from Conversations API messages)

### FCM Token Registration

Mobile devices must register their Firebase Cloud Messaging token to receive push notifications.

#### POST `/api/v1/MobileDevice/People/{peopleId}/RegisterFirebaseToken`

Registers or updates the FCM token for a mobile device.

**Headers:**
```http
Authorization: Bearer {auth-token}
X-Device-Id: {instance-id}
CmsHost: {instance}.tpsdb.com
Content-Type: application/json
```

**Path Parameters:**
- `peopleId` (int) - The person ID associated with the device

**Request Body:**
```json
{
  "fcmToken": "dF1JzQ2NjM0NjI4OTY5OnNlY3JldA...your-fcm-token"
}
```

**Response:**
```json
{
  "id": 12345,
  "peopleId": 67890,
  "instanceId": "550e8400-e29b-41d4-a716-446655440000",
  "fcmToken": "dF1JzQ2NjM0NjI4OTY5OnNlY3JldA...your-fcm-token",
  "deviceTypeId": 2,
  "created": "2024-01-01T10:00:00Z",
  "lastSeen": "2024-01-14T10:00:00Z",
  "appVersion": "2.5.0"
}
```

**Success:** HTTP 200
**Errors:**
- HTTP 400 - Invalid FCM token format
- HTTP 403 - Not authorized to register for this person

**Notes:**
- The `fcmToken` is provided by the Firebase SDK on the mobile device
- Tokens should be refreshed when Firebase indicates the token has changed
- The server stores only one FCM token per device (latest wins)

### Push Notification Delivery

Push notifications are delivered server-side via Firebase Cloud Messaging based on notification lists created by administrators or automated workflows.

**Push Notification Payload:**
```json
{
  "notification": {
    "title": "Youth Group Meeting",
    "body": "Tomorrow at 7pm - see you there!"
  },
  "data": {
    "notificationListId": "4501",
    "notificationTypeId": "2",
    "notificationSentBy": "123",
    "notificationSentDate": "01/14/2024 14:30:00"
  }
}
```

**Handling Incoming Push:**
When a push notification arrives, the mobile app should:
1. Display the notification in the system tray
2. Extract `notificationListId` from the data payload
3. When user taps the notification, fetch full details via `GET /api/v1/Notifications/Lists/{notificationListId}`
4. Mark notifications as read via the appropriate endpoint

### In-App Notifications

In-app notifications provide a notification center experience within the mobile app, separate from system push notifications.

#### GET `/api/v1/People/{peopleId}/Alerts`

Gets all alert notifications for a person.

**Query Parameters:**
- `page` (int, optional) - Page number (1-based, default 1)
- `take` (int, optional) - Results per page (default 20)

**Response:**
```json
{
  "notifications": [
    {
      "notificationId": 5001,
      "notificationListId": 4501,
      "peopleId": 12345,
      "isRead": false,
      "readDate": null,
      "isPushed": true,
      "pushDate": "2024-01-14T14:30:00Z",
      "createdDate": "2024-01-14T14:00:00Z",
      "notificationTypeId": 1,
      "action": "view",
      "objectType": "Event",
      "objectTypeId": 789,
      "parentType": null,
      "parentId": null,
      "title": "Event Canceled",
      "content": "Youth retreat has been postponed to next month.",
      "contentLink": "/events/789",
      "isSent": true,
      "sendPush": true,
      "scheduledDate": "2024-01-14T14:30:00Z",
      "expirationDate": "2024-02-14T00:00:00Z",
      "sentDate": "2024-01-14T14:30:00Z",
      "headerCreatedDate": "2024-01-14T14:00:00Z",
      "headerCreatedBy": 100,
      "headerCreatedByName": "Admin User"
    }
  ],
  "page": 1,
  "take": 20,
  "total": 3
}
```

#### GET `/api/v1/People/{peopleId}/Alerts/Unread`

Gets only unread alert notifications.

**Query Parameters:** Same as above

**Use Case:** Display unread count badge on the Alerts tab.

#### GET `/api/v1/People/{peopleId}/Notifications`

Gets all general notifications (UserFeed type) for a person.

**Response:** Same structure as Alerts

#### GET `/api/v1/People/{peopleId}/Notifications/Unread`

Gets only unread general notifications.

#### GET `/api/v1/People/{peopleId}/Messages`

Gets all message-type notifications (not to be confused with Conversations API messages).

**Response:** Same structure as Alerts

#### GET `/api/v1/People/{peopleId}/Messages/Unread`

Gets only unread message notifications.

### Notification Actions

#### POST `/api/v1/Notifications/{notificationId}/MarkRead`

Marks a single notification as read.

**Request Body:**
```json
{
  "notificationId": 5001,
  "peopleId": 12345
}
```

**Response:**
```json
{
  "notificationId": 5001,
  "isRead": true,
  "readDate": "2024-01-14T15:00:00Z"
}
```

#### POST `/api/v1/Notifications/{notificationTypeId}/People/{peopleId}/MarkAllRead`

Marks all notifications of a specific type as read for a person.

**Path Parameters:**
- `notificationTypeId` - 1 (Alerts), 2 (UserFeed), or 3 (Messages)
- `peopleId` - Person ID

**Response:**
```json
{
  "markedCount": 12
}
```

#### DELETE `/api/v1/Notifications/{notificationId}`

Deletes a single notification.

**Success:** HTTP 200

#### DELETE `/api/v1/Notifications/{notificationTypeId}/People/{peopleId}/DeleteAll`

Deletes all notifications of a specific type for a person.

**Success:** HTTP 200 with count of deleted notifications

### Notification Lists

Notification lists are batches of notifications sent to multiple people at once (e.g., "Youth Group Reminder" sent to all youth members).

#### GET `/api/v1/Notifications/Lists/{notificationListId}`

Gets details of a notification list.

**Response:**
```json
{
  "notificationListId": 4501,
  "notificationTypeId": 2,
  "title": "Youth Group Meeting",
  "content": "Tomorrow at 7pm - see you there!",
  "contentLink": "/events/789",
  "action": "view",
  "objectType": "Event",
  "objectTypeId": 789,
  "isSent": true,
  "sendPush": true,
  "scheduledDate": "2024-01-14T14:30:00Z",
  "expirationDate": "2024-02-14T00:00:00Z",
  "sentDate": "2024-01-14T14:30:00Z",
  "createdDate": "2024-01-14T14:00:00Z",
  "createdBy": 100,
  "notificationStatus": 2,
  "recipientCount": 45
}
```

**Notification Statuses:**
- `1` - Pending
- `2` - Scheduled
- `3` - Sent
- `4` - Expired

### Real-Time Updates via Polling

The mobile app does not currently use WebSocket/SignalR connections. Instead, it should poll for updates at regular intervals.

**Polling Strategy:**

```typescript
// Poll for unread notifications every 30 seconds when app is in foreground
class NotificationPollingService {
  private pollingInterval: number = 30000; // 30 seconds
  private intervalId?: number;

  startPolling(peopleId: number) {
    this.intervalId = setInterval(async () => {
      await this.checkForUpdates(peopleId);
    }, this.pollingInterval);
  }

  stopPolling() {
    if (this.intervalId) {
      clearInterval(this.intervalId);
    }
  }

  async checkForUpdates(peopleId: number) {
    try {
      // Check each notification type for unread count
      const [alertsUnread, notificationsUnread, messagesUnread] = await Promise.all([
        this.getUnreadCount(peopleId, 1), // Alerts
        this.getUnreadCount(peopleId, 2), // Notifications
        this.getUnreadCount(peopleId, 3)  // Messages
      ]);

      // Update badges
      this.updateBadges({
        alerts: alertsUnread,
        notifications: notificationsUnread,
        messages: messagesUnread,
        total: alertsUnread + notificationsUnread + messagesUnread
      });

      // If in notification view, refresh the list
      if (this.isNotificationViewActive()) {
        await this.refreshNotificationList(peopleId);
      }
    } catch (error) {
      console.error('Polling error:', error);
    }
  }

  async getUnreadCount(peopleId: number, typeId: number): Promise<number> {
    const response = await apiClient.get<NotificationResponse>(
      `/v1/Notifications/${typeId}/People/${peopleId}/Unread?page=1&take=1`
    );
    return response.total;
  }
}
```

**Polling Best Practices:**
1. Poll every 30 seconds when app is in foreground
2. Stop polling when app goes to background
3. Poll immediately when app returns to foreground
4. Poll immediately after user action (e.g., marking as read)
5. Use exponential backoff if server errors occur
6. Batch requests when possible (check all types in parallel)

### Future: SignalR Real-Time Support

While not currently implemented for mobile, the legacy web application uses SignalR for real-time updates. A future enhancement could add SignalR support to the mobile API.

**Potential SignalR Implementation:**

```typescript
// FUTURE - Not currently supported in CmsApi
import * as signalR from '@microsoft/signalr';

class NotificationHubService {
  private connection?: signalR.HubConnection;

  async connect(authToken: string, cmsHost: string) {
    this.connection = new signalR.HubConnectionBuilder()
      .withUrl(`https://${cmsHost}/hubs/notifications`, {
        accessTokenFactory: () => authToken
      })
      .withAutomaticReconnect()
      .build();

    // Subscribe to notification events
    this.connection.on('NotificationReceived', (notification) => {
      this.handleNotification(notification);
    });

    this.connection.on('NotificationRead', (notificationId) => {
      this.markNotificationRead(notificationId);
    });

    this.connection.on('ConversationMessageReceived', (message) => {
      this.handleNewMessage(message);
    });

    await this.connection.start();
  }

  async subscribe(peopleId: number) {
    await this.connection?.invoke('SubscribeToNotifications', peopleId);
  }

  async disconnect() {
    await this.connection?.stop();
  }
}
```

**Note:** The above SignalR code is illustrative only. The current mobile API does not provide SignalR hubs. Use the polling strategy described above.

### Data Models (TypeScript)

```typescript
interface NotificationDto {
  // Notification Item Properties
  notificationId: number;
  notificationListId: number;
  peopleId: number;
  isRead: boolean;
  readDate?: Date;
  isPushed: boolean;
  pushDate?: Date;
  createdDate?: Date;

  // Notification Header Properties
  notificationTypeId?: number;
  action?: string;
  objectType?: string;
  objectTypeId?: number;
  parentType?: string;
  parentId?: number;
  title: string;
  content: string;
  contentLink?: string;
  isSent?: boolean;
  sendPush?: boolean;
  scheduledDate?: Date;
  expirationDate?: Date;
  sentDate?: Date;
  headerCreatedDate?: Date;
  headerCreatedBy?: number;
  headerCreatedByName?: string;

  // Deprecated (use content instead)
  body?: string;
}

interface NotificationResponse {
  notifications: NotificationDto[];
  page: number;
  take: number;
  total: number;
}

interface NotificationListDto {
  notificationListId: number;
  notificationTypeId: number;
  title: string;
  content: string;
  contentLink?: string;
  action?: string;
  objectType?: string;
  objectTypeId?: number;
  isSent?: boolean;
  sendPush?: boolean;
  scheduledDate?: Date;
  expirationDate?: Date;
  sentDate?: Date;
  createdDate?: Date;
  createdBy?: number;
  notificationStatus?: number; // 1=Pending, 2=Scheduled, 3=Sent, 4=Expired
  recipientCount?: number;
}

interface MobileAppDeviceDto {
  id: number;
  peopleId?: number;
  userId?: number;
  instanceId: string;
  fcmToken?: string;
  deviceTypeId: number;
  created: Date;
  lastSeen: Date;
  appVersion?: string;
}
```

### Implementation Guidelines

#### 1. FCM Token Registration Flow

```typescript
// On app startup or when Firebase token changes
async function registerFCMToken() {
  try {
    // Get FCM token from Firebase SDK
    const fcmToken = await messaging().getToken();

    if (!fcmToken) {
      console.warn('No FCM token available');
      return;
    }

    // Register with server
    await apiClient.post(
      `/v1/MobileDevice/People/${currentUser.peopleId}/RegisterFirebaseToken`,
      { fcmToken }
    );

    console.log('FCM token registered successfully');
  } catch (error) {
    console.error('FCM registration failed:', error);
  }
}

// Listen for token refresh
messaging().onTokenRefresh(async (newToken) => {
  await apiClient.post(
    `/v1/MobileDevice/People/${currentUser.peopleId}/RegisterFirebaseToken`,
    { fcmToken: newToken }
  );
});
```

#### 2. Push Notification Handling

```typescript
// Handle foreground push notifications
messaging().onMessage(async (remoteMessage) => {
  console.log('Foreground push:', remoteMessage);

  // Extract notification list ID
  const notificationListId = remoteMessage.data?.notificationListId;

  if (notificationListId) {
    // Fetch full details
    const details = await apiClient.get<NotificationListDto>(
      `/v1/Notifications/Lists/${notificationListId}`
    );

    // Show in-app notification
    showInAppNotification(details);

    // Refresh notification list if user is viewing it
    if (isNotificationScreenActive()) {
      await refreshNotifications();
    }
  }
});

// Handle background/quit push notification taps
messaging().onNotificationOpenedApp(async (remoteMessage) => {
  const notificationListId = remoteMessage.data?.notificationListId;

  if (notificationListId) {
    // Navigate to notification detail screen
    navigation.navigate('NotificationDetail', { notificationListId });
  }
});

// Handle notification tap when app was completely quit
messaging()
  .getInitialNotification()
  .then(async (remoteMessage) => {
    if (remoteMessage) {
      const notificationListId = remoteMessage.data?.notificationListId;
      if (notificationListId) {
        // Set initial route
        setInitialRoute('NotificationDetail', { notificationListId });
      }
    }
  });
```

#### 3. Notification Center Implementation

```typescript
// Notification center screen
class NotificationCenterScreen extends React.Component {
  state = {
    activeTab: 'alerts', // 'alerts', 'notifications', 'messages'
    alerts: [],
    notifications: [],
    messages: [],
    unreadCounts: { alerts: 0, notifications: 0, messages: 0 }
  };

  async componentDidMount() {
    await this.loadNotifications();
    this.startPolling();
  }

  componentWillUnmount() {
    this.stopPolling();
  }

  async loadNotifications() {
    const peopleId = this.props.currentUser.peopleId;

    // Load all three types in parallel
    const [alerts, notifications, messages, unreadAlerts, unreadNotifications, unreadMessages] = 
      await Promise.all([
        apiClient.get(`/v1/People/${peopleId}/Alerts?page=1&take=50`),
        apiClient.get(`/v1/People/${peopleId}/Notifications?page=1&take=50`),
        apiClient.get(`/v1/People/${peopleId}/Messages?page=1&take=50`),
        apiClient.get(`/v1/People/${peopleId}/Alerts/Unread?page=1&take=1`),
        apiClient.get(`/v1/People/${peopleId}/Notifications/Unread?page=1&take=1`),
        apiClient.get(`/v1/People/${peopleId}/Messages/Unread?page=1&take=1`)
      ]);

    this.setState({
      alerts: alerts.notifications,
      notifications: notifications.notifications,
      messages: messages.notifications,
      unreadCounts: {
        alerts: unreadAlerts.total,
        notifications: unreadNotifications.total,
        messages: unreadMessages.total
      }
    });
  }

  async markAsRead(notificationId: number) {
    await apiClient.post(`/v1/Notifications/${notificationId}/MarkRead`, {
      notificationId,
      peopleId: this.props.currentUser.peopleId
    });

    // Refresh list
    await this.loadNotifications();
  }

  async markAllRead(typeId: number) {
    await apiClient.post(
      `/v1/Notifications/${typeId}/People/${this.props.currentUser.peopleId}/MarkAllRead`
    );

    // Refresh list
    await this.loadNotifications();
  }

  startPolling() {
    this.pollingService = new NotificationPollingService();
    this.pollingService.startPolling(this.props.currentUser.peopleId);

    // Listen for badge updates
    this.pollingService.on('badgesUpdated', (counts) => {
      this.setState({ unreadCounts: counts });
    });
  }

  stopPolling() {
    this.pollingService?.stopPolling();
  }
}
```

#### 4. Deep Linking from Notifications

```typescript
// Handle notification content links
function navigateFromNotification(notification: NotificationDto) {
  const { objectType, objectTypeId, contentLink } = notification;

  // Use object type to determine navigation
  switch (objectType) {
    case 'Event':
      navigation.navigate('EventDetail', { eventId: objectTypeId });
      break;

    case 'Conversation':
      navigation.navigate('ConversationDetail', { conversationId: objectTypeId });
      break;

    case 'CheckIn':
      navigation.navigate('CheckInFamily');
      break;

    case 'Poll':
      navigation.navigate('PollDetail', { pollId: objectTypeId });
      break;

    default:
      // Fallback to contentLink if available
      if (contentLink) {
        navigation.navigate('WebView', { url: contentLink });
      }
  }
}
```

### Best Practices

**Push Notifications:**
1. Always request notification permissions before registering FCM token
2. Handle token refresh events and re-register immediately
3. Test foreground, background, and quit app states
4. Provide meaningful notification titles and bodies
5. Use notification data payload for deep linking

**In-App Notifications:**
1. Show unread badges on tabs and icons
2. Implement pull-to-refresh on notification lists
3. Provide "Mark all as read" action
4. Support swipe-to-delete on individual notifications
5. Auto-mark as read when user taps a notification
6. Expire old notifications client-side based on `expirationDate`

**Polling:**
1. Use 30-second intervals in foreground
2. Stop polling when app is backgrounded
3. Poll immediately when returning to foreground
4. Implement exponential backoff on errors
5. Batch API calls to minimize network usage
6. Cache notification lists for offline viewing

**Performance:**
1. Limit initial page load to 20-50 notifications
2. Implement infinite scroll for older notifications
3. Cache notification images and avatars
4. Debounce rapid mark-as-read actions
5. Show optimistic UI updates before API confirmation

---

## Additional Resources

### API Architecture

The modern BVCMS API is built using:
- **Azure Functions** - Serverless HTTP endpoints
- **.NET 8** - Latest .NET runtime
- **Entity Framework Core** - Data access layer
- **Dependency Injection** - Service-oriented architecture

### API Versioning

All modern endpoints are versioned in the URL path:
- `/api/v1/...` - Current stable API
- `/api/v0/...` - Legacy compatibility endpoints (deprecated)

### Configuration Settings

Check these settings in your BVCMS instance:

**Mobile App Settings:**
- `MobileBrandColorPrimary` - Primary brand color (hex)
- `MobileBrandColorPalette` - Full color palette (JSON)
- `MobileNewPersonFieldRequirement` - Required fields for new accounts
- `UseMobileQuickSignInCodes` - Enable one-time verification codes
- `MobileQuickSignInCodeSMS` - SMS message template
- `MobileQuickSignInCodeSubject` - Email subject template

**Check-In Settings:**
- `EarlyCheckin` - Default early check-in window (minutes)
- `LateCheckin` - Default late check-in window (minutes)
- `ExcludeRecommendSetting` - Tag to exclude from recommendations
- `LimitNumberSearch` - Require 7+ digits for phone search (legacy)

### Database Tables

Key tables used by the API:

**Mobile App:**
- `MobileAppDevices` - Device registrations and authentication
- `MobileAppPreferences` - User preferences per device

**Check-In:**
- `CheckInPending` - Pre-check-in selections
- `Attend` - Attendance records
- `CheckInTimes` - Check-in timestamps and activities
- `CheckinProfiles` - Profile configurations
- `CheckinProfileSettings` - Profile settings

**Organizations:**
- `Organizations` - Classes/groups available for check-in
- `Meetings` - Specific meeting instances
- `OrganizationMembers` - Membership records

### Code Locations

**Modern API (Recommended):**
- `CmsApi/EndPoints/MobileApp/` - Mobile app authentication and settings
- `CmsApi/EndPoints/Attendance/Attendance_CheckIn_*.cs` - Check-in operations
- `CmsApi/Helpers/Authentication/` - Authentication middleware
- `Tps.Core/Services/Interfaces/` - Service interfaces
- `Tps.Core/Services/Implementations/` - Service implementations

**Legacy API (For Reference):**
- `CmsWeb/Areas/Public/Controllers/MobileAPIv2Controller.cs` - Legacy mobile API
- `CmsWeb/Areas/Public/Controllers/CheckInAPIv2Controller.cs` - Legacy check-in API
- `CmsWeb/Areas/Public/Models/MobileAPIv2/` - Legacy models
- `CmsWeb/Areas/Public/Models/CheckInAPIv2/` - Legacy check-in models

### Authentication Flow Details

The modern authentication uses a two-tier token system:

1. **Device Token** - Identifies the device
   - Generated on first verification code request
   - Stored in `MobileAppDevices.Authentication`
   - Used for device-only operations

2. **User Token** - Identifies the authenticated user
   - Generated after device registration to person
   - Includes user ID and people ID claims
   - Required for all user-specific operations

### Common Integration Patterns

#### Pattern 1: Single-Page Pre-Check-In

```typescript
// Load everything in one call
const family = await apiClient.get<FamilyCheckIn>(
  `/v1/CheckIn/Family/${familyId}`
);

// Check for existing pending check-ins
const pending = await apiClient.get<PendingCheckIn[]>(
  `/v1/CheckIn/Family/${familyId}/PendingCheckIn`
);

// Merge and display
const memberSelections = mergePendingWithAvailable(family, pending);
```

#### Pattern 2: Progressive Loading

```typescript
// Load user profile first (cached)
const profile = await getCachedUserProfile();

// Load check-in data on demand
const family = await loadCheckInData(profile.familyId);

// Load pending asynchronously
loadPendingCheckIns(profile.familyId).then(pending => {
  updateUI(pending);
});
```

#### Pattern 3: Optimistic UI Updates

```typescript
// Update UI immediately
updatePendingCheckInUI(newSelection);

// Save in background
try {
  await savePendingCheckIn(newSelection);
  showSuccess("Saved!");
} catch (error) {
  // Revert UI on error
  revertPendingCheckInUI();
  showError("Could not save selection");
}
```

### Performance Best Practices

1. **Minimize API Calls**
   - Cache user profile for entire session
   - Cache family check-in data for 5 minutes
   - Batch pending check-in updates

2. **Image Optimization**
   - Request appropriately sized QR codes (300x300 for mobile)
   - Cache QR codes locally
   - Use lazy loading for family member photos

3. **Network Efficiency**
   - Compress request/response bodies
   - Use HTTP/2 if available
   - Implement request debouncing for search

### Troubleshooting

#### Common Issues

**Issue: "Authentication failed"**
- Verify `Authorization` header is present
- Verify `X-Device-Id` header matches registered device
- Check token hasn't expired
- Ensure `CmsHost` header matches your instance

**Issue: "Pending check-in not found"**
- This is normal if no pending check-ins exist
- Don't treat as error - just show empty state

**Issue: "Person not found"**
- Verify person ID exists in database
- Check user has permission to access that person
- Ensure person is in same family as authenticated user

**Issue: "Meeting is closed/full"**
- Check organization settings for `SuspendCheckin`
- Verify capacity hasn't been reached
- Check if meeting has been manually closed

### Migration from Legacy API

If migrating from the legacy API:

| Legacy Endpoint | Modern Equivalent |
|----------------|-------------------|
| `/MobileAPIv2/QuickSignIn` | `/api/v1/MobileDevice/RequestVerificationCode` |
| `/MobileAPIv2/Authenticate` | `/api/v1/MobileDevice/VerifyCode` + `RegisterDeviceToPerson` |
| `/CheckInAPIv2/GetFamily` | `/api/v1/CheckIn/Family/{familyId}` |
| `/CheckInAPIv2/GetPendingCheckIn` | `/api/v1/CheckIn/Family/{familyId}/PendingCheckIn` |
| `/CheckInAPIv2/UpdatePendingCheckIn` | `/api/v1/CheckIn/Family/{familyId}/PendingCheckIn/Replace` |
| `/CheckInAPIv2/GetQRCodeForPerson` | `/api/v1/CheckIn/People/{peopleId}/Qrcode/Text` |

**Key Differences:**
- Modern API uses standard REST paths instead of message-based routing
- Authentication is header-based (Bearer tokens) instead of message properties
- Responses are direct JSON objects instead of wrapped in a message envelope
- Error handling uses HTTP status codes instead of message error codes

### Support & Documentation

For questions or issues:
- Review code in `CmsApi/EndPoints/` directory
- Check service implementations in `Tps.Core/Services/`
- Review authentication middleware in `CmsApi/Helpers/Authentication/`
- Consult OpenAPI documentation (if enabled)

### API Testing Tools

Recommended tools for testing:
- **Postman** - API testing and documentation
- **curl** - Command-line testing
- **Azure Functions Core Tools** - Local development testing

Example curl request:
```bash
curl -X POST "https://yourinstance.tpsdb.com/api/v1/MobileDevice/RequestVerificationCode" \
  -H "Content-Type: application/json" \
  -H "X-Device-Id: 550e8400-e29b-41d4-a716-446655440000" \
  -d '{
    "emailAddress": "test@example.com",
    "deviceTypeId": 1,
    "appVersion": "2024.1.0",
    "newPerson": false
  }'
```

---

## Revision History

- **Version 2.0** - Updated to focus on modern CmsApi Azure Functions endpoints (January 2024)
- **Version 1.0** - Initial documentation with legacy API (January 2024)
