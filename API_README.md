# Modern Chat API 文档 (v2.3)

## 概述

Modern Chat API 是一套基于 HTTP 的 RESTful 风格接口，旨在为开发者提供灵活、高效的聊天服务集成方案。
该 API 采用统一的入口设计 (`api.php`)，通过 POST 参数指定资源 (`resource`) 和动作 (`action`) 来进行路由分发。

---

## 基础信息

- **API 入口**: `/api/api.php`
- **请求协议**: HTTP / HTTPS
- **请求方法**: `POST` (推荐)
- **数据格式**: JSON (`application/json`) 或 Form Data (`application/x-www-form-urlencoded`)
- **字符编码**: UTF-8
- **身份验证**: 基于 Cookie Session (浏览器自动处理) 或 HTTP Header (需客户端维护 Cookie)

---

## 通用响应结构

所有 API 请求均返回统一的 JSON 格式：

```json
{
    "success": true,      // 请求是否成功 (boolean)
    "message": "操作成功", // 提示信息 (string)
    "data": {             // 业务数据 (object/array/null)
        ...
    }
}
```

- **HTTP 状态码**:
  - `200`: 成功
  - `400`: 请求参数错误
  - `401`: 未授权/未登录
  - `404`: 资源不存在
  - `500`: 服务器内部错误

---

## 模块详解

### 1. 认证模块 (Auth)

资源名称: `auth`

#### 1.1 用户登录
- **Action**: `login`
- **参数**:
  - `email` (string, required): 用户邮箱
  - `password` (string, required): 用户密码（明文，建议使用加密）
  - `encrypted_password` (string, optional): RSA 加密后的密码（推荐）
- **响应**: 包含用户基本信息（不含密码）

#### 1.2 用户注册
- **Action**: `register`
- **参数**:
  - `username` (string, required): 用户名
  - `email` (string, required): 邮箱
  - `password` (string, required): 密码
  - `phone` (string, optional): 手机号
  - `sms_code` (string, optional): 短信验证码（当短信验证开启时必填）
- **响应**: `{"user_id": 123}`

#### 1.3 退出登录
- **Action**: `logout`
- **参数**: 无
- **说明**: 清除服务器 Session 并将用户状态置为离线。

#### 1.4 检查登录状态
- **Action**: `check_status`
- **参数**: 无
- **响应**: `{"is_logged_in": true, "user_id": 123}`

#### 1.5 获取 RSA 公钥
- **Action**: `get_public_key`
- **参数**: 无
- **响应**: `{"public_key": "MIIBIjANBg..."}`
- **说明**: 用于前端加密密码，配合 `encrypted_password` 参数使用

---

### 2. 用户模块 (User)

资源名称: `user` (需登录)

#### 2.1 获取用户信息
- **Action**: `get_info`
- **参数**:
  - `user_id` (int, optional): 目标用户ID，不传则获取当前用户
- **响应**: 用户详细信息对象

#### 2.2 更新个人信息
- **Action**: `update_info`
- **参数**:
  - 任意允许更新的字段，如 `username`, `phone`, `signature` 等
- **说明**: 仅能更新当前登录用户的信息。

#### 2.3 搜索用户
- **Action**: `search`
- **参数**:
  - `q` (string, required): 搜索关键词 (用户名/邮箱)
- **响应**: 用户列表数组

#### 2.4 修改密码
- **Action**: `update_password`
- **参数**:
  - `old_password` (string, required): 原密码
  - `new_password` (string, required): 新密码
- **说明**: 新密码需包含至少2种字符类型（大小写字母、数字、特殊符号）

#### 2.5 注销账号
- **Action**: `delete_account`
- **参数**:
  - `password` (string, required): 当前密码确认
- **说明**: 注销后账号数据将无法恢复

---

### 3. 好友模块 (Friends)

资源名称: `friends` (需登录)

#### 3.1 获取好友列表
- **Action**: `list`
- **参数**: 无
- **响应**: 好友列表数组

#### 3.2 发送好友请求
- **Action**: `send_request`
- **参数**:
  - `friend_id` (int, required): 目标用户ID

#### 3.3 删除好友
- **Action**: `delete`
- **参数**:
  - `friend_id` (int, required): 目标好友ID

#### 3.4 获取好友申请列表
- **Action**: `get_requests`
- **参数**: 无
- **响应**: 待处理的申请列表

#### 3.5 处理好友申请
- **Action**: `accept_request` / `reject_request`
- **参数**:
  - `request_id` (int, required): 申请记录ID

---

### 4. 消息模块 (Messages)

资源名称: `messages` (需登录)

#### 4.1 获取私聊历史
- **Action**: `history`
- **参数**:
  - `friend_id` (int, required): 好友ID
- **响应**: 消息记录数组

#### 4.2 发送私聊消息
- **Action**: `send`
- **参数**:
  - `receiver_id` (int, required): 接收者ID
  - `content` (string, required): 消息内容 (纯文本)
- **响应**: `{"message_id": 1001}`

#### 4.3 发送文件消息
- **Action**: `send_file`
- **参数**:
  - `receiver_id` (int, required): 接收者ID
  - `file_path` (string, required): 文件路径
  - `file_name` (string, required): 文件名
  - `file_size` (int, required): 文件大小
  - `file_type` (string, required): 文件类型
  - `audio_duration` (int, optional): 音频时长（秒）
- **响应**: `{"message_id": 1001, "audio_duration": 10}`

#### 4.4 撤回消息
- **Action**: `recall`
- **参数**:
  - `message_id` (int, required): 消息ID
- **说明**: 只能撤回 2 分钟内自己发送的消息

#### 4.5 删除消息
- **Action**: `delete`
- **参数**:
  - `message_id` (int, required): 消息ID
- **说明**: 仅删除自己的消息记录

#### 4.6 标记消息已读
- **Action**: `mark_read`
- **参数**:
  - `friend_id` (int, required): 好友ID
- **说明**: 将该好友发送的所有未读消息标记为已读

#### 4.7 获取未读消息数量
- **Action**: `get_unread`
- **参数**: 无
- **响应**: `{"unread_count": 5}`

---

### 5. 群组模块 (Groups)

资源名称: `groups` (需登录)

#### 5.1 获取群组列表
- **Action**: `list`
- **参数**: 无
- **响应**: 已加入的群组列表

#### 5.2 获取群组信息
- **Action**: `info`
- **参数**:
  - `group_id` (int, required): 群组ID

#### 5.3 创建群组
- **Action**: `create`
- **参数**:
  - `name` (string, required): 群名称
  - `member_ids` (array, optional): 初始成员ID数组

#### 5.4 获取群成员
- **Action**: `members`
- **参数**:
  - `group_id` (int, required)

#### 5.5 添加群成员
- **Action**: `add_members`
- **参数**:
  - `group_id` (int, required)
  - `member_ids` (array, required): 待添加的用户ID数组

#### 5.6 获取群消息
- **Action**: `messages`
- **参数**:
  - `group_id` (int, required)

#### 5.7 发送群消息
- **Action**: `send_message`
- **参数**:
  - `group_id` (int, required)
  - `content` (string, required)

#### 5.8 发送群文件消息
- **Action**: `send_file`
- **参数**:
  - `group_id` (int, required): 群组ID
  - `file_path` (string, required): 文件路径
  - `file_name` (string, required): 文件名
  - `file_size` (int, required): 文件大小
  - `file_type` (string, required): 文件类型
  - `audio_duration` (int, optional): 音频时长（秒）
- **响应**: `{"message_id": 1001, "audio_duration": 10}`

#### 5.9 撤回群消息
- **Action**: `recall`
- **参数**:
  - `message_id` (int, required): 消息ID
- **说明**: 发送者、群主或管理员可撤回 2 分钟内的消息

#### 5.10 删除群消息
- **Action**: `delete_message`
- **参数**:
  - `message_id` (int, required): 消息ID
- **说明**: 仅删除自己的消息记录

#### 5.11 置顶群聊
- **Action**: `pin`
- **参数**:
  - `group_id` (int, required): 群组ID

#### 5.12 取消置顶群聊
- **Action**: `unpin`
- **参数**:
  - `group_id` (int, required): 群组ID

#### 5.13 退出群聊
- **Action**: `leave`
- **参数**:
  - `group_id` (int, required)
- **说明**: 群主不能退出，需先转让群主

#### 5.14 踢出群成员
- **Action**: `remove_member`
- **参数**:
  - `group_id` (int, required)
  - `user_id` (int, required): 要踢出的用户ID
- **说明**: 仅群主和管理员可用

#### 5.15 设置管理员
- **Action**: `set_admin`
- **参数**:
  - `group_id` (int, required)
  - `user_id` (int, required)
  - `is_admin` (boolean, optional): 默认 true
- **说明**: 仅群主可用，管理员最多 9 人

#### 5.16 转让群主
- **Action**: `transfer`
- **参数**:
  - `group_id` (int, required)
  - `new_owner_id` (int, required): 新群主ID
- **说明**: 仅群主可用

#### 5.17 标记群消息已读
- **Action**: `mark_read`
- **参数**:
  - `group_id` (int, required)

#### 5.18 修改群名称
- **Action**: `update_name`
- **参数**:
  - `group_id` (int, required): 群组ID
  - `name` (string, required): 新群名称
- **说明**: 仅群主可用

#### 5.19 解散群聊
- **Action**: `delete`
- **参数**:
  - `group_id` (int, required): 群组ID
- **说明**: 仅群主可用，解散后群聊数据将删除

#### 5.20 邀请好友加入群聊
- **Action**: `invite`
- **参数**:
  - `group_id` (int, required): 群组ID
  - `friend_id` (int, required): 好友ID
- **说明**: 群成员可邀请好友加入

---

### 6. 文件上传模块 (Upload)

资源名称: `upload` (需登录)

- **Action**: 默认 (无需指定)
- **请求方式**: `POST` (multipart/form-data)
- **参数**:
  - `file` (file, required): 文件对象
- **响应**:
  ```json
  {
      "file_path": "/uploads/xxx.jpg",
      "file_name": "image.jpg",
      "file_size": 1024,
      "mime_type": "image/jpeg"
  }
  ```

---

### 7. 头像上传模块 (Avatar)

资源名称: `avatar` (需登录)

- **Action**: 默认 (无需指定)
- **请求方式**: `POST` (multipart/form-data)
- **参数**:
  - `avatar` (file, required): 头像图片文件
- **支持格式**: JPG, PNG, GIF, WEBP
- **大小限制**: 2MB
- **响应**:
  ```json
  {
      "avatar": "/uploads/avatar_1_1234567890.jpg"
  }
  ```

---

### 8. 会话模块 (Sessions)

资源名称: `sessions` (需登录)

#### 8.1 获取会话列表
- **Action**: `list`
- **参数**: 无
- **响应**: 会话列表数组

#### 8.2 清除未读计数
- **Action**: `clear_unread`
- **参数**:
  - `session_id` (int, required): 会话ID

#### 8.3 置顶会话
- **Action**: `pin`
- **参数**:
  - `session_id` (int, required): 会话ID

#### 8.4 取消置顶会话
- **Action**: `unpin`
- **参数**:
  - `session_id` (int, required): 会话ID

---

### 9. 系统公告模块 (Announcements)

资源名称: `announcements`

#### 9.1 获取最新公告
- **Action**: `get`
- **参数**: 无
- **说明**: 无需登录，但已读状态需要登录
- **响应**:
  ```json
  {
      "has_new_announcement": true,
      "announcement": {
          "id": 1,
          "title": "系统公告标题",
          "content": "公告内容",
          "created_at": "2024-01-01 12:00:00",
          "admin_name": "管理员"
      },
      "has_read": false
  }
  ```

#### 9.2 标记公告已读
- **Action**: `mark_read`
- **参数**:
  - `announcement_id` (int, required): 公告ID
- **说明**: 需要登录

---

### 10. 音乐模块 (Music)

资源名称: `music`

#### 10.1 获取音乐列表
- **Action**: `list`
- **参数**: 无
- **说明**: 无需登录
- **响应**:
  ```json
  {
      "code": 200,
      "data": [
          {
              "id": 1,
              "name": "歌曲名称",
              "filename": "song.mp3",
              "artistsname": "歌手名",
              "sort_order": 0
          }
      ]
  }
  ```

---

### 11. 文件访问模块 (File)

资源名称: `file`

#### 11.1 获取文件
- **Action**: `get`
- **参数**:
  - `path` (string, required): 文件路径（相对于项目根目录）
- **说明**: 用于图片/封面等文件的访问，走 API 域名避免小程序域名限制
- **响应**: 文件内容（根据文件类型设置相应的 Content-Type）

---

### 12. 扫码登录模块 (Scan Login)

资源名称: `scan_login`

#### 12.1 生成登录二维码
- **Action**: `generate`
- **参数**: 无
- **响应**:
  ```json
  {
      "token": "xxx",
      "qid": "scan_xxx",
      "expires_at": "2024-01-01 12:05:00",
      "qr_url": "https://example.com/scan_login.php?qid=scan_xxx"
  }
  ```

#### 12.2 扫描二维码
- **Action**: `scan`
- **参数**:
  - `qid` (string, required): 登录标识
- **响应**: `{"success": true, "message": "扫描成功"}`

#### 12.3 确认登录
- **Action**: `confirm`
- **参数**:
  - `qid` (string, required): 登录标识
  - `user_id` (int, required): 用户ID
- **响应**: `{"success": true, "message": "登录确认成功"}`

#### 12.4 拒绝登录
- **Action**: `reject`
- **参数**:
  - `qid` (string, required): 登录标识
- **响应**: `{"success": true, "message": "已拒绝登录"}`

#### 12.5 查询登录状态
- **Action**: `status`
- **参数**:
  - `qid` (string, required): 登录标识
- **响应**:
  ```json
  {
      "status": "success",
      "user": {
          "id": 123,
          "username": "用户名",
          "email": "user@example.com",
          "avatar": "/uploads/avatar_123.jpg"
      }
  }
  ```

#### 12.6 获取登录IP地址
- **Action**: `get_ip`
- **参数**:
  - `qid` (string, required): 登录标识
- **响应**: `{"ip_address": "192.168.1.1"}`

---

### 13. 短信验证模块 (SMS)

资源名称: `sms`

#### 13.1 发送验证码
- **Action**: `send`
- **参数**:
  - `phone` (string, required): 手机号
- **响应**: `{"success": true, "message": "验证码已发送，请在5分钟内输入"}`

#### 13.2 验证验证码
- **Action**: `verify`
- **参数**:
  - `phone` (string, required): 手机号
  - `code` (string, required): 验证码
- **响应**: `{"success": true, "message": "验证成功"}`

---

### 14. 版本信息模块 (Version)

资源名称: `version`

#### 14.1 获取APP版本信息
- **Action**: `app`
- **参数**: 无
- **说明**: 无需登录，手机端检查更新用
- **响应**:
  ```json
  {
      "version": "0.7.4",
      "versionCode": 74,
      "versionName": "0.7.4",
      "minVersionCode": 0,
      "note": "暂无新版本",
      "downloadUrl": ""
  }
  ```

---

## RSA 加密登录流程

1. 获取公钥：调用 `auth/get_public_key` 获取 RSA 公钥
2. 前端加密：使用公钥加密密码
3. 发送登录请求：使用 `encrypted_password` 参数发送加密后的密码

```javascript
// 示例代码
const { JSEncrypt } = require('jsencrypt');

async function login(email, password) {
    // 1. 获取公钥
    const keyRes = await fetch('/api/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ resource: 'auth', action: 'get_public_key' })
    });
    const { data: { public_key } } = await keyRes.json();
    
    // 2. 加密密码
    const encrypt = new JSEncrypt();
    encrypt.setPublicKey(public_key);
    const encryptedPassword = encrypt.encrypt(password);
    
    // 3. 登录
    const loginRes = await fetch('/api/api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            resource: 'auth',
            action: 'login',
            email: email,
            encrypted_password: encryptedPassword
        })
    });
    
    return await loginRes.json();
}
```

---

## 调用示例 (JavaScript Fetch)

### 发送消息示例

```javascript
const apiBase = '/api/api.php';

async function sendMessage(receiverId, text) {
    try {
        const response = await fetch(apiBase, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                resource: 'messages',
                action: 'send',
                receiver_id: receiverId,
                content: text
            })
        });

        const result = await response.json();
        
        if (result.success) {
            console.log('发送成功, ID:', result.data.message_id);
        } else {
            console.error('发送失败:', result.message);
        }
    } catch (error) {
        console.error('网络错误:', error);
    }
}
```

### 发送文件消息示例

```javascript
async function sendFileMessage(receiverId, fileInfo) {
    const response = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            resource: 'messages',
            action: 'send_file',
            receiver_id: receiverId,
            file_path: fileInfo.file_path,
            file_name: fileInfo.file_name,
            file_size: fileInfo.file_size,
            file_type: fileInfo.file_type,
            audio_duration: fileInfo.audio_duration
        })
    });
    
    return await response.json();
}
```

### 上传文件示例

```javascript
async function uploadFile(fileInput) {
    const formData = new FormData();
    formData.append('resource', 'upload');
    formData.append('file', fileInput.files[0]);

    const response = await fetch(apiBase, {
        method: 'POST',
        body: formData // 自动设置 Content-Type 为 multipart/form-data
    });
    
    return await response.json();
}
```

### 上传头像示例

```javascript
async function uploadAvatar(fileInput) {
    const formData = new FormData();
    formData.append('resource', 'avatar');
    formData.append('avatar', fileInput.files[0]);

    const response = await fetch(apiBase, {
        method: 'POST',
        body: formData
    });
    
    return await response.json();
}
```

### 修改密码示例

```javascript
async function changePassword(oldPassword, newPassword) {
    const response = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            resource: 'user',
            action: 'update_password',
            old_password: oldPassword,
            new_password: newPassword
        })
    });
    
    return await response.json();
}
```

### 获取系统公告示例

```javascript
async function getAnnouncement() {
    const response = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            resource: 'announcements',
            action: 'get'
        })
    });
    
    return await response.json();
}
```

### 获取音乐列表示例

```javascript
async function getMusicList() {
    const response = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            resource: 'music',
            action: 'list'
        })
    });
    
    return await response.json();
}
```

### 扫码登录示例

```javascript
// 生成二维码
async function generateQRCode() {
    const response = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            resource: 'scan_login',
            action: 'generate'
        })
    });
    
    const result = await response.json();
    if (result.success) {
        return result.data;
    }
    throw new Error(result.message);
}

// 轮询登录状态
async function checkLoginStatus(qid) {
    const response = await fetch(apiBase, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            resource: 'scan_login',
            action: 'status',
            qid: qid
        })
    });
    
    return await response.json();
}
```

---

## 更新日志

### v2.3 (2026-04-20)
- 新增消息模块 `send_file` 发送文件消息接口
- 新增消息模块 `delete` 删除消息接口
- 新增群组模块 `send_file` 发送群文件消息接口
- 新增群组模块 `delete_message` 删除群消息接口
- 新增群组模块 `pin` 置顶群聊接口
- 新增群组模块 `unpin` 取消置顶群聊接口
- 新增会话模块 `pin` 置顶会话接口
- 新增会话模块 `unpin` 取消置顶会话接口
- 新增文件访问模块 `file` 获取文件接口
- 新增扫码登录模块 `scan_login` 相关接口
- 新增短信验证模块 `sms` 相关接口
- 新增版本信息模块 `version` 获取APP版本信息接口
- 更新用户注册接口，支持短信验证码验证

### v2.2 (2024-01-15)
- 新增用户模块 `update_password` 修改密码接口
- 新增用户模块 `delete_account` 注销账号接口
- 新增群组模块 `update_name` 修改群名称接口
- 新增群组模块 `delete` 解散群聊接口
- 新增群组模块 `invite` 邀请好友加入群聊接口
- 新增系统公告模块 `announcements`
- 新增音乐模块 `music`

### v2.1
- 初始版本
