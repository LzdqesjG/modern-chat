# config.json使用说明

### 默认文件内容

```json
{
    "Create_a_group_chat_for_all_members": true,
    "Restrict_registration": true,
    "Restrict_registration_ip": 3,
    "ban_system": true,
    "user_name_max": 12,
    "upload_files_max": 150
}
```

---

### 字段详解

 - `Create_a_group_chat_for_all_members`: 是否用户注册后自动加入一个总群聊
 - `Restrict_registration`: 是否对相同IP限制注册数量
 - `Restrict_registration_ip`: 同上, 指定限制数量
 - `ban_system`: 是否启用封禁系统
 - `user_name_max`: 你希望最大设置的用户名长度
 - `upload_files_max`: 你希望用户最大可发送的文件大小
