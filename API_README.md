# Modern Chat API 文档 (v2.0)

## 概述

Modern Chat API 是一套基于 HTTP 的 RESTful 风格接口，旨在为开发者提供灵活、高效的聊天服务集成方案。
该 API 采用统一的入口设计 (`api.php`)，通过 POST 参数指定资源 (`resource`) 和动作 (`action`) 来进行路由分发。

---

## 基础信息

- **API 入口**: `/api.php`
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
  - `password` (string, required): 用户密码
- **响应**: 包含用户基本信息（不含密码）

#### 1.2 用户注册
- **Action**: `register`
- **参数**:
  - `username` (string, required): 用户名
  - `email` (string, required): 邮箱
  - `password` (string, required): 密码
  - `phone` (string, optional): 手机号
- **响应**: `{"user_id": 123}`

#### 1.3 退出登录
- **Action**: `logout`
- **参数**: 无
- **说明**: 清除服务器 Session 并将用户状态置为离线。

#### 1.4 检查登录状态
- **Action**: `check_status`
- **参数**: 无
- **响应**: `{"is_logged_in": true, "user_id": 123}`

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

## 调用示例 (JavaScript Fetch)

### 发送消息示例

```javascript
const apiBase = '/chat/api.php';

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
