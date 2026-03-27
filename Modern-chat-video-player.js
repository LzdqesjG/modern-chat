/**
 * Modern Chat Video Player
 * 自定义视频播放器，包含完整的控制功能和美观的UI
 * 版本：V1.8.0
 * 禁止商用
 */
// 播放器版本
const PLAYER_VERSION = '1.8.0';

// 全局配置
const ModernChatVideoPlayerConfig = {
    // 是否允许下载视频（默认开启）
    UseDownload: true,
    // 是否禁止开发者工具（默认关闭）
    DeveloperToolsAreProhibited: false
};

// 先定义VideoProtection类，作为ModernChatVideoPlayer的别名
const VideoProtection = {};

// 为VideoProtection添加必要的方法
VideoProtection.addWatermark = function(videoElement) {
    // 检查视频元素是否已经有水印
    if (videoElement.querySelector('.video-watermark')) {
        return;
    }
    
    const watermark = document.createElement('div');
    watermark.className = 'video-watermark';
    watermark.style.position = 'absolute';
    watermark.style.top = '10px';
    watermark.style.left = '10px';
    watermark.style.color = 'rgba(255, 255, 255, 0.7)';
    watermark.style.fontSize = '16px';
    watermark.style.fontWeight = 'bold';
    watermark.style.pointerEvents = 'none';
    watermark.style.zIndex = '99999';
    watermark.style.textShadow = '2px 2px 4px rgba(0, 0, 0, 0.8)';
    watermark.style.userSelect = 'none';
    watermark.style.whiteSpace = 'nowrap';
    watermark.style.padding = '5px 10px';
    watermark.style.background = 'rgba(0, 0, 0, 0.3)';
    watermark.style.borderRadius = '4px';
    watermark.textContent = 'Modern Chat Video Player';
    
    // 确保视频元素有定位
    if (videoElement.style.position === '' || videoElement.style.position === 'static') {
        videoElement.style.position = 'relative';
    }
    
    // 确保视频元素的容器也有定位
    const container = videoElement.parentElement;
    if (container && (container.style.position === '' || container.style.position === 'static')) {
        container.style.position = 'relative';
    }
    
    // 将水印直接添加到视频元素中
    videoElement.appendChild(watermark);
    
    console.log('水印已添加到视频元素:', videoElement);
};

// 为VideoProtection添加playWithMSE方法
VideoProtection.playWithMSE = async function(videoElement, videoUrl) {
    // 对于本地缓存的blob URL，直接使用传统播放方式
    if (videoUrl.startsWith('blob:')) {
        console.log('Using traditional playback for local cached video');
        return false;
    }
    
    if (!window.MediaSource) {
        console.warn('MediaSource not supported, falling back to direct playback');
        return false;
    }
    
    try {
        const mediaSource = new MediaSource();
        const blobUrl = URL.createObjectURL(mediaSource);
        
        // 确保activeBlobUrls集合存在
        if (!ModernChatVideoPlayer.activeBlobUrls) {
            ModernChatVideoPlayer.activeBlobUrls = new Set();
        }
        
        // 确保其他集合存在
        if (!ModernChatVideoPlayer.blobUrlMap) {
            ModernChatVideoPlayer.blobUrlMap = new Map();
        }
        if (!ModernChatVideoPlayer.blobUrlCreationTime) {
            ModernChatVideoPlayer.blobUrlCreationTime = new Map();
        }
        if (!ModernChatVideoPlayer.blobUrlContext) {
            ModernChatVideoPlayer.blobUrlContext = new Map();
        }
        
        // 生成唯一的会话ID
        const sessionId = Math.random().toString(36).substr(2, 15);
        
        // 将MSE创建的blob URL添加到活跃blob URL集合中（在设置到视频元素之前）
        ModernChatVideoPlayer.activeBlobUrls.add(blobUrl);
        // 激活blob URL
        if (window.__activateBlobUrl) {
            window.__activateBlobUrl(blobUrl);
        }
        // 存储blob URL与原始URL的映射
        ModernChatVideoPlayer.blobUrlMap.set(blobUrl, videoUrl);
        // 存储blob URL的创建时间
        ModernChatVideoPlayer.blobUrlCreationTime.set(blobUrl, Date.now());
        // 存储blob URL的访问上下文
        ModernChatVideoPlayer.blobUrlContext.set(blobUrl, {
            type: 'mse',
            timestamp: Date.now(),
            referrer: document.referrer || window.location.href,
            sessionId: sessionId,
            videoElement: videoElement
        });
        
        // 移除固定的过期时间，改为在视频播放完成或播放器被销毁时释放blob URL
        // 这样可以确保视频在需要时一直可用
        
        // 监听视频播放完成事件，释放blob URL
        videoElement.addEventListener('ended', function cleanup() {
            if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                if (ModernChatVideoPlayer.blobUrlMap) {
                    ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                }
                if (ModernChatVideoPlayer.blobUrlCreationTime) {
                    ModernChatVideoPlayer.blobUrlCreationTime.delete(blobUrl);
                }
                if (ModernChatVideoPlayer.blobUrlContext) {
                    ModernChatVideoPlayer.blobUrlContext.delete(blobUrl);
                }
                // 停用blob URL
                if (window.__deactivateBlobUrl) {
                    window.__deactivateBlobUrl(blobUrl);
                }
                try {
                    URL.revokeObjectURL(blobUrl);
                    console.log('视频播放完成，已释放blob URL:', blobUrl);
                } catch (error) {
                    console.warn('释放blob URL失败:', error);
                }
            }
            videoElement.removeEventListener('ended', cleanup);
        });
        
        // 监听视频错误事件，释放blob URL
        videoElement.addEventListener('error', function cleanup() {
            if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                if (ModernChatVideoPlayer.blobUrlMap) {
                    ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                }
                if (ModernChatVideoPlayer.blobUrlCreationTime) {
                    ModernChatVideoPlayer.blobUrlCreationTime.delete(blobUrl);
                }
                if (ModernChatVideoPlayer.blobUrlContext) {
                    ModernChatVideoPlayer.blobUrlContext.delete(blobUrl);
                }
                // 停用blob URL
                if (window.__deactivateBlobUrl) {
                    window.__deactivateBlobUrl(blobUrl);
                }
                try {
                    URL.revokeObjectURL(blobUrl);
                    console.log('视频播放错误，已释放blob URL:', blobUrl);
                } catch (error) {
                    console.warn('释放blob URL失败:', error);
                }
            }
            videoElement.removeEventListener('error', cleanup);
        });
        
        // 现在设置blob URL到视频元素
        videoElement.src = blobUrl;
        
        // 为视频元素添加会话ID标记
        videoElement.dataset.sessionId = sessionId;
        
        return new Promise((resolve) => {
            mediaSource.addEventListener('sourceopen', async () => {
                try {
                    // 尝试根据视频URL推断MIME类型
                    let mimeType = 'video/mp4; codecs="avc1.42E01E, mp4a.40.2"';
                    const inferredType = ModernChatVideoPlayer.getVideoMimeType(videoUrl);
                    if (inferredType) {
                        // 根据不同的MIME类型设置不同的codecs
                        if (inferredType.includes('webm')) {
                            mimeType = 'video/webm; codecs="vp8, vorbis"';
                        } else if (inferredType.includes('ogg')) {
                            mimeType = 'video/ogg; codecs="theora, vorbis"';
                        } else if (inferredType.includes('matroska')) {
                            mimeType = 'video/x-matroska; codecs="avc1.42E01E, mp4a.40.2"';
                        } else if (inferredType.includes('hevc')) {
                            // 使用支持4K的HEVC编码参数
                            mimeType = 'video/mp4; codecs="hvc1.1.6.L153.90, mp4a.40.2"';
                        }
                        // 对于其他格式，使用默认的MP4 codecs
                    }
                    
                    // 检查MediaSource是否仍然打开
                    if (mediaSource.readyState !== 'open') {
                        throw new Error('MediaSource is not open');
                    }
                    
                    // 尝试创建sourceBuffer
                    let sourceBuffer;
                    try {
                        sourceBuffer = mediaSource.addSourceBuffer(mimeType);
                    } catch (error) {
                        console.error('Failed to create sourceBuffer:', error);
                        // 如果创建sourceBuffer失败，尝试使用更通用的MIME类型
                        try {
                            mimeType = 'video/mp4; codecs="avc1.42E01E, mp4a.40.2"';
                            sourceBuffer = mediaSource.addSourceBuffer(mimeType);
                        } catch (error) {
                            console.error('Failed to create sourceBuffer with fallback MIME type:', error);
                            throw error;
                        }
                    }
                    
                    // 分段获取视频数据，增强安全性
                    await VideoProtection.fetchVideoSegments(videoUrl, sourceBuffer, mediaSource, sessionId);
                    
                    resolve(true);
                } catch (error) {
                    console.error('MSE playback failed:', error);
                    // 只有当 MediaSource 仍然打开时才调用 endOfStream
                    if (mediaSource.readyState === 'open') {
                        mediaSource.endOfStream(MediaSource.END_OF_STREAM_ERROR);
                    }
                    // 清理blob URL
                    if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                        ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                        URL.revokeObjectURL(blobUrl);
                    }
                    resolve(false);
                }
            });
            
            // 处理MediaSource错误
            mediaSource.addEventListener('error', () => {
                console.error('MediaSource error');
                // 清理blob URL
                if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                    ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                    URL.revokeObjectURL(blobUrl);
                }
                resolve(false);
            });
        });
    } catch (error) {
        console.error('MSE setup failed:', error);
        return false;
    }
};

// 为VideoProtection添加fetchVideoSegments方法
VideoProtection.fetchVideoSegments = async function(videoUrl, sourceBuffer, mediaSource, sessionId) {
    try {
        // 检查videoUrl是否是假的blob URL
        let finalUrl = videoUrl;
        if (typeof finalUrl === 'string' && finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
            // 替换为真实URL
            const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(finalUrl);
            if (realUrl) {
                finalUrl = realUrl;
            }
        }
        
        // 检查finalUrl是否是本地缓存的真实blob URL
        if (finalUrl.startsWith('blob:') && !finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
            // 对于本地缓存的blob URL，直接从blob获取数据
            const response = await fetch(finalUrl);
            const blob = await response.blob();
            const arrayBuffer = await blob.arrayBuffer();
            
            // 检查mediaSource是否仍然打开
            if (mediaSource.readyState === 'open') {
                // 检查sourceBuffer是否仍然可用
                if (sourceBuffer.updating) {
                    // 等待sourceBuffer完成更新
                    await new Promise(resolve => {
                        sourceBuffer.addEventListener('updateend', resolve, { once: true });
                    });
                }
                
                // 将整个视频数据添加到sourceBuffer
                try {
                    sourceBuffer.appendBuffer(arrayBuffer);
                    
                    // 等待append完成
                    await new Promise((resolve, reject) => {
                        sourceBuffer.addEventListener('updateend', resolve, { once: true });
                        sourceBuffer.addEventListener('error', () => {
                            const errorMessage = sourceBuffer.error ? `SourceBuffer error: ${sourceBuffer.error.message}` : 'Unknown SourceBuffer error';
                            reject(new Error(errorMessage));
                        }, { once: true });
                    });
                } catch (error) {
                    console.error('Error appending buffer to SourceBuffer:', error);
                    throw error;
                }
            }
            
            // 所有数据添加完成
            if (mediaSource.readyState === 'open') {
                mediaSource.endOfStream();
            }
        } else {
            // 对于远程视频URL，使用分段fetch获取数据
            // 首先获取视频的总大小
            const headResponse = await fetch(finalUrl, {
                method: 'HEAD',
                headers: {
                    'X-Modern-Chat-Session': sessionId,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Referer': document.referrer || window.location.href
                }
            });
            
            if (!headResponse.ok) {
                throw new Error('Failed to get video info');
            }
            
            const contentLength = parseInt(headResponse.headers.get('content-length') || '0');
            if (contentLength === 0) {
                throw new Error('Cannot determine video size');
            }
            
            // 分段获取视频数据
            const chunkSize = 4 * 1024 * 1024; // 4MB chunks for better 4K video performance
            const totalChunks = Math.ceil(contentLength / chunkSize);
            
            for (let i = 0; i < totalChunks; i++) {
                const start = i * chunkSize;
                const end = Math.min(start + chunkSize - 1, contentLength - 1);
                
                // 检查mediaSource是否仍然打开
                if (mediaSource.readyState === 'closed' || mediaSource.readyState === 'ended') {
                    break;
                }
                
                // 检查sourceBuffer是否仍然可用
                if (sourceBuffer.updating) {
                    // 等待sourceBuffer完成更新
                    await new Promise(resolve => {
                        sourceBuffer.addEventListener('updateend', resolve, { once: true });
                    });
                }
                
                // 发送范围请求获取视频片段
                const response = await fetch(finalUrl, {
                    headers: {
                        'Range': `bytes=${start}-${end}`,
                        'X-Modern-Chat-Session': sessionId,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Referer': document.referrer || window.location.href
                    }
                });
                
                if (!response.ok) {
                    throw new Error(`Failed to fetch video segment ${i + 1}/${totalChunks}`);
                }
                
                const chunkBuffer = await response.arrayBuffer();
                
                // 检查chunkBuffer是否有效
                if (chunkBuffer.byteLength === 0) {
                    throw new Error(`Empty video segment ${i + 1}/${totalChunks}`);
                }
                
                // 添加数据到sourceBuffer
                if (mediaSource.readyState === 'open') {
                    sourceBuffer.appendBuffer(chunkBuffer);
                    
                    // 等待append完成
                    await new Promise((resolve, reject) => {
                        sourceBuffer.addEventListener('updateend', resolve, { once: true });
                        sourceBuffer.addEventListener('error', reject, { once: true });
                    });
                }
            }
            
            // 所有数据添加完成
            if (mediaSource.readyState === 'open') {
                mediaSource.endOfStream();
            }
        }
    } catch (error) {
        console.error('Failed to fetch video segments:', error);
        if (mediaSource.readyState === 'open') {
            mediaSource.endOfStream(MediaSource.END_OF_STREAM_ERROR);
        }
        throw error;
    }
};

// 先定义ModernChatVideoPlayer类，然后再定义VideoProtection类
class ModernChatVideoPlayer {
    /**
     * 使用MediaSource Extensions播放视频
     */
    static async playWithMSE(videoElement, videoUrl) {
        // 对于本地缓存的blob URL，直接使用传统播放方式
        if (videoUrl.startsWith('blob:')) {
            console.log('Using traditional playback for local cached video');
            return false;
        }
        
        if (!window.MediaSource) {
            console.warn('MediaSource not supported, falling back to direct playback');
            return false;
        }
        
        try {
            const mediaSource = new MediaSource();
            const blobUrl = URL.createObjectURL(mediaSource);
            
            // 确保activeBlobUrls集合存在
            if (!ModernChatVideoPlayer.activeBlobUrls) {
                ModernChatVideoPlayer.activeBlobUrls = new Set();
            }
            
            // 确保其他集合存在
            if (!ModernChatVideoPlayer.blobUrlMap) {
                ModernChatVideoPlayer.blobUrlMap = new Map();
            }
            if (!ModernChatVideoPlayer.blobUrlCreationTime) {
                ModernChatVideoPlayer.blobUrlCreationTime = new Map();
            }
            if (!ModernChatVideoPlayer.blobUrlContext) {
                ModernChatVideoPlayer.blobUrlContext = new Map();
            }
            
            // 生成唯一的会话ID
            const sessionId = Math.random().toString(36).substr(2, 15);
            
            // 将MSE创建的blob URL添加到活跃blob URL集合中（在设置到视频元素之前）
            ModernChatVideoPlayer.activeBlobUrls.add(blobUrl);
            // 激活blob URL
            if (window.__activateBlobUrl) {
                window.__activateBlobUrl(blobUrl);
            }
            // 存储blob URL与原始URL的映射
            ModernChatVideoPlayer.blobUrlMap.set(blobUrl, videoUrl);
            // 存储blob URL的创建时间
            ModernChatVideoPlayer.blobUrlCreationTime.set(blobUrl, Date.now());
            // 存储blob URL的访问上下文
            ModernChatVideoPlayer.blobUrlContext.set(blobUrl, {
                type: 'mse',
                timestamp: Date.now(),
                referrer: document.referrer || window.location.href,
                sessionId: sessionId,
                videoElement: videoElement
            });
            
            // 移除固定的过期时间，改为在视频播放完成或播放器被销毁时释放blob URL
            // 这样可以确保视频在需要时一直可用
            
            // 监听视频播放完成事件，释放blob URL
            videoElement.addEventListener('ended', function cleanup() {
                if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                    ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                    if (ModernChatVideoPlayer.blobUrlMap) {
                        ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlCreationTime) {
                        ModernChatVideoPlayer.blobUrlCreationTime.delete(blobUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlContext) {
                        ModernChatVideoPlayer.blobUrlContext.delete(blobUrl);
                    }
                    // 停用blob URL
                    if (window.__deactivateBlobUrl) {
                        window.__deactivateBlobUrl(blobUrl);
                    }
                    try {
                        URL.revokeObjectURL(blobUrl);
                        console.log('视频播放完成，已释放blob URL:', blobUrl);
                    } catch (error) {
                        console.warn('释放blob URL失败:', error);
                    }
                }
                videoElement.removeEventListener('ended', cleanup);
            });
            
            // 监听视频错误事件，释放blob URL
            videoElement.addEventListener('error', function cleanup() {
                if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                    ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                    if (ModernChatVideoPlayer.blobUrlMap) {
                        ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlCreationTime) {
                        ModernChatVideoPlayer.blobUrlCreationTime.delete(blobUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlContext) {
                        ModernChatVideoPlayer.blobUrlContext.delete(blobUrl);
                    }
                    // 停用blob URL
                    if (window.__deactivateBlobUrl) {
                        window.__deactivateBlobUrl(blobUrl);
                    }
                    try {
                        URL.revokeObjectURL(blobUrl);
                        console.log('视频播放错误，已释放blob URL:', blobUrl);
                    } catch (error) {
                        console.warn('释放blob URL失败:', error);
                    }
                }
                videoElement.removeEventListener('error', cleanup);
            });
            
            // 现在设置blob URL到视频元素
            videoElement.src = blobUrl;
            
            // 为视频元素添加会话ID标记
            videoElement.dataset.sessionId = sessionId;
            
            return new Promise((resolve) => {
                mediaSource.addEventListener('sourceopen', async () => {
                    try {
                        // 尝试根据视频URL推断MIME类型
                        let mimeType = 'video/mp4; codecs="avc1.42E01E, mp4a.40.2"';
                        const inferredType = ModernChatVideoPlayer.getVideoMimeType(videoUrl);
                        if (inferredType) {
                            // 根据不同的MIME类型设置不同的codecs
                            if (inferredType.includes('webm')) {
                                mimeType = 'video/webm; codecs="vp8, vorbis"';
                            } else if (inferredType.includes('ogg')) {
                                mimeType = 'video/ogg; codecs="theora, vorbis"';
                            } else if (inferredType.includes('matroska')) {
                                mimeType = 'video/x-matroska; codecs="avc1.42E01E, mp4a.40.2"';
                            } else if (inferredType.includes('hevc')) {
                                // 使用支持4K的HEVC编码参数
                                mimeType = 'video/mp4; codecs="hvc1.1.6.L153.90, mp4a.40.2"';
                            }
                            // 对于其他格式，使用默认的MP4 codecs
                        }
                        
                        // 检查MediaSource是否仍然打开
                        if (mediaSource.readyState !== 'open') {
                            throw new Error('MediaSource is not open');
                        }
                        
                        // 尝试创建sourceBuffer
                        let sourceBuffer;
                        try {
                            sourceBuffer = mediaSource.addSourceBuffer(mimeType);
                        } catch (error) {
                            console.error('Failed to create sourceBuffer:', error);
                            // 如果创建sourceBuffer失败，尝试使用更通用的MIME类型
                            try {
                                // 尝试标准H.264编码
                                mimeType = 'video/mp4; codecs="avc1.42E01E, mp4a.40.2"';
                                sourceBuffer = mediaSource.addSourceBuffer(mimeType);
                            } catch (error) {
                                console.error('Failed to create sourceBuffer with H.264 fallback:', error);
                                try {
                                    // 尝试HEVC编码的不同profile
                                    mimeType = 'video/mp4; codecs="hvc1.1.6.L153.90, mp4a.40.2"';
                                    sourceBuffer = mediaSource.addSourceBuffer(mimeType);
                                } catch (error) {
                                    console.error('Failed to create sourceBuffer with HEVC fallback:', error);
                                    try {
                                        // 尝试不带codecs的MIME类型
                                        mimeType = 'video/mp4';
                                        sourceBuffer = mediaSource.addSourceBuffer(mimeType);
                                    } catch (error) {
                                        console.error('Failed to create sourceBuffer with generic MIME type:', error);
                                        throw error;
                                    }
                                }
                            }
                        }
                        
                        // 分段获取视频数据，增强安全性
                        await VideoProtection.fetchVideoSegments(videoUrl, sourceBuffer, mediaSource, sessionId);
                        
                        resolve(true);
                    } catch (error) {
                        console.error('MSE playback failed:', error);
                        // 只有当 MediaSource 仍然打开时才调用 endOfStream
                        if (mediaSource.readyState === 'open') {
                            mediaSource.endOfStream(MediaSource.END_OF_STREAM_ERROR);
                        }
                        // 清理blob URL
                        if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                            ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                            URL.revokeObjectURL(blobUrl);
                        }
                        resolve(false);
                    }
                });
                
                // 处理MediaSource错误
                mediaSource.addEventListener('error', () => {
                    console.error('MediaSource error');
                    // 清理blob URL
                    if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                        ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                        URL.revokeObjectURL(blobUrl);
                    }
                    resolve(false);
                });
            });
        } catch (error) {
            console.error('MSE setup failed:', error);
            return false;
        }
    }
    
    /**
     * 分段获取视频数据并喂给SourceBuffer
     */
    static async fetchVideoSegments(videoUrl, sourceBuffer, mediaSource, sessionId) {
        try {
            // 检查videoUrl是否是假的blob URL
            let finalUrl = videoUrl;
            if (typeof finalUrl === 'string' && finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                // 替换为真实URL
                const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(finalUrl);
                if (realUrl) {
                    finalUrl = realUrl;
                }
            }
            
            // 检查finalUrl是否是本地缓存的真实blob URL
            if (finalUrl.startsWith('blob:') && !finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                // 对于本地缓存的blob URL，直接从blob获取数据
                const response = await fetch(finalUrl);
                const blob = await response.blob();
                const arrayBuffer = await blob.arrayBuffer();
                
                // 检查mediaSource是否仍然打开
                if (mediaSource.readyState === 'open') {
                    // 检查sourceBuffer是否仍然可用
                    if (sourceBuffer.updating) {
                        // 等待sourceBuffer完成更新
                        await new Promise(resolve => {
                            sourceBuffer.addEventListener('updateend', resolve, { once: true });
                        });
                    }
                    
                    // 将整个视频数据添加到sourceBuffer
                    try {
                        sourceBuffer.appendBuffer(arrayBuffer);
                        
                        // 等待append完成
                        await new Promise((resolve, reject) => {
                            sourceBuffer.addEventListener('updateend', resolve, { once: true });
                            sourceBuffer.addEventListener('error', () => {
                                const errorMessage = sourceBuffer.error ? `SourceBuffer error: ${sourceBuffer.error.message}` : 'Unknown SourceBuffer error';
                                reject(new Error(errorMessage));
                            }, { once: true });
                        });
                    } catch (error) {
                        console.error('Error appending buffer to SourceBuffer:', error);
                        throw error;
                    }
                }
                
                // 所有数据添加完成
                if (mediaSource.readyState === 'open') {
                    mediaSource.endOfStream();
                }
            } else {
                // 对于远程视频URL，使用分段fetch获取数据
                // 首先获取视频的总大小
                const headResponse = await fetch(finalUrl, {
                    method: 'HEAD',
                    headers: {
                        'X-Modern-Chat-Session': sessionId,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Referer': document.referrer || window.location.href
                    }
                });
                
                if (!headResponse.ok) {
                    throw new Error('Failed to get video info');
                }
                
                const contentLength = parseInt(headResponse.headers.get('content-length') || '0');
                if (contentLength === 0) {
                    throw new Error('Cannot determine video size');
                }
                
                // 分段获取视频数据
                const chunkSize = 4 * 1024 * 1024; // 4MB chunks for better 4K video performance
                const totalChunks = Math.ceil(contentLength / chunkSize);
                
                for (let i = 0; i < totalChunks; i++) {
                    const start = i * chunkSize;
                    const end = Math.min(start + chunkSize - 1, contentLength - 1);
                    
                    // 检查mediaSource是否仍然打开
                    if (mediaSource.readyState === 'closed' || mediaSource.readyState === 'ended') {
                        break;
                    }
                    
                    // 检查sourceBuffer是否仍然可用
                    if (sourceBuffer.updating) {
                        // 等待sourceBuffer完成更新
                        await new Promise(resolve => {
                            sourceBuffer.addEventListener('updateend', resolve, { once: true });
                        });
                    }
                    
                    // 发送范围请求获取视频片段
                    const response = await fetch(finalUrl, {
                        headers: {
                            'Range': `bytes=${start}-${end}`,
                            'X-Modern-Chat-Session': sessionId,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Referer': document.referrer || window.location.href
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error(`Failed to fetch video segment ${i + 1}/${totalChunks}`);
                    }
                    
                    const chunkBuffer = await response.arrayBuffer();
                    
                    // 检查chunkBuffer是否有效
                    if (chunkBuffer.byteLength === 0) {
                        throw new Error(`Empty video segment ${i + 1}/${totalChunks}`);
                    }
                    
                    // 添加数据到sourceBuffer
                    if (mediaSource.readyState === 'open') {
                        sourceBuffer.appendBuffer(chunkBuffer);
                        
                        // 等待append完成
                        await new Promise((resolve, reject) => {
                            sourceBuffer.addEventListener('updateend', resolve, { once: true });
                            sourceBuffer.addEventListener('error', reject, { once: true });
                        });
                    }
                }
                
                // 所有数据添加完成
                if (mediaSource.readyState === 'open') {
                    mediaSource.endOfStream();
                }
            }
        } catch (error) {
            console.error('Failed to fetch video segments:', error);
            if (mediaSource.readyState === 'open') {
                mediaSource.endOfStream(MediaSource.END_OF_STREAM_ERROR);
            }
            throw error;
        }
    }
    
    /**
     * 为视频添加水印
     */
    static addWatermark(videoElement) {
        // 检查视频元素是否已经有水印
        if (videoElement.querySelector('.video-watermark')) {
            return;
        }
        
        const watermark = document.createElement('div');
        watermark.className = 'video-watermark';
        watermark.style.position = 'absolute';
        watermark.style.top = '10px';
        watermark.style.left = '10px';
        watermark.style.color = 'rgba(255, 255, 255, 0.9)';
        watermark.style.fontSize = '18px';
        watermark.style.fontWeight = 'bold';
        watermark.style.pointerEvents = 'none';
        watermark.style.zIndex = '999999';
        watermark.style.textShadow = '2px 2px 4px rgba(0, 0, 0, 0.8)';
        watermark.style.userSelect = 'none';
        watermark.style.whiteSpace = 'nowrap';
        watermark.style.padding = '8px 12px';
        watermark.style.background = 'rgba(0, 0, 0, 0.6)';
        watermark.style.borderRadius = '6px';
        watermark.style.fontFamily = 'Arial, sans-serif';
        watermark.textContent = 'Modern Chat Video Player';
        
        // 确保视频元素有定位
        if (videoElement.style.position === '' || videoElement.style.position === 'static') {
            videoElement.style.position = 'relative';
        }
        
        // 确保视频元素的容器也有定位
        const container = videoElement.parentElement;
        if (container && (container.style.position === '' || container.style.position === 'static')) {
            container.style.position = 'relative';
        }
        
        // 将水印直接添加到视频元素中
        videoElement.appendChild(watermark);
        
        console.log('水印已添加到视频元素:', videoElement);
    }
}

class ModernChatVideoPlayerClass {
    constructor(videoElement, originalVideoElement = null) {
        this.video = videoElement;
        this.originalVideo = originalVideoElement;
        this.container = null;
        this.controls = null;
        this.playBtn = null;
        this.volumeBtn = null;
        this.volumeSlider = null;
        this.downloadBtn = null;
        this.fullscreenBtn = null;
        this.progressContainer = null;
        this.progressBar = null;
        this.bufferBar = null;
        this.timeDisplay = null;
        this.contextMenu = null;
        this.modals = {};
        this.speedUpdateInterval = null;
        this.statsCloseListener = null;
        this.mouseMoveTimer = null;
        this.isControlsVisible = true;
        this.videoCompleted = false;
        
        // FPS计算相关属性
        this.fpsFrameCount = 0;
        this.fpsLastTime = 0;
        this.currentFps = 0;
        this.fpsIntervalId = null;
        
        // FPS控制相关属性
        this.fpsWarningElement = null;
        this.fpsLimit = 30; // 默认30FPS
        this.fpsMode = '30fps'; // 30fps, 60fps, 120fps, auto
        
        // 保存原始视频URL
        let originalUrl = videoElement.src;
        // 检查是否是假的blob URL
        if (typeof originalUrl === 'string' && originalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
            // 替换为真实URL
            const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(originalUrl);
            if (realUrl) {
                originalUrl = realUrl;
            }
        }
        this.originalVideoUrl = originalUrl;
        
        // 视频色彩和音效设置
        this.colorSettings = {
            brightness: 100,
            contrast: 100,
            saturation: 100,
            hue: 0,
            grayscale: 0
        };
        
        this.audioSettings = {
            volume: 100,
            bass: 0,
            treble: 0,
            spatialAudio: true, // 360°环绕音效默认开启
            spatialIntensity: 100 // 环绕强度固定为100%
        };
        
        // 初始化播放器
        this.init();
    }
    
    /**
     * 初始化播放器
     */
    init() {
        // 创建播放器容器
        this.createContainer();
        
        // 创建控制栏
        this.createControls();
        
        // 创建右键菜单
        this.createContextMenu();
        
        // 创建模态框
        this.createModals();
        
        // 绑定事件
        this.bindEvents();
        
        // 应用初始设置
        this.applyColorSettings();
        this.updateVolumeDisplay();
        
        // 在控制台显示初始化信息
        this.showInitMessage();
    }
    
    /**
     * 显示初始化信息
     */
    showInitMessage() {
        console.log(`Modern Chat Video Player ${PLAYER_VERSION} 初始化中......`);
        const completionArt = "\n"+
            "\n     __  ___          __                        __          __           _     __                    __                       " +
            "\n    /  |/  /___  ____/ /__  _________     _____/ /_  ____ _/ /_   _   __(_)___/ /__  ____     ____  / /___ ___  _____  _____  " +
            "\n   / /|_/ / __ \/ __  / _ \/ ___/ __ \   / ___/ __ \/ __ `/ __/  | | / / / __  / _ \/ __ \   / __ \/ / __ `/ / / / _ \/ ___/  " +
            "\n  / /  / / /_/ / /_/ /  __/ /  / / / /  / /__/ / / / /_/ / /_    | |/ / / /_/ /  __/ /_/ /  / /_/ / / /_/ / /_/ /  __/ /      " +
            "\n /_/  /_/\____/\__,_/\___/_/  /_/ /_/   \___/_/ /_/\__,_/\__/    |___/_/\__,_/\___/\____/  / .___/_/\__,_/\__, /\___/_/       " +
            "\n                                                                                          /_/            /____/               ";
        console.log(completionArt);
        console.log("Modern Chat Video Player " + PLAYER_VERSION + " 初始化完毕！");
    }
    
    /**
     * 创建播放器容器
     */
    createContainer() {
        // 创建容器元素
        this.container = document.createElement('div');
        this.container.className = 'modern-chat-video-player';
        
        // 将视频元素移动到容器中
        this.video.parentNode.insertBefore(this.container, this.video);
        this.container.appendChild(this.video);
        
        // 隐藏原始视频控件
        this.video.controls = false;
        
        // 添加data-modern-player属性
        this.video.setAttribute('data-modern-player', 'true');
        
        // 添加水印
        VideoProtection.addWatermark(this.video);
        
        // 创建FPS警告元素
        this.createFpsWarning();
    }
    
    /**
     * 创建FPS警告元素
     */
    createFpsWarning() {
        this.fpsWarningElement = document.createElement('div');
        this.fpsWarningElement.className = 'modern-chat-video-fps-warning';
        this.fpsWarningElement.style.position = 'absolute';
        this.fpsWarningElement.style.top = '10px';
        this.fpsWarningElement.style.left = '50%';
        this.fpsWarningElement.style.transform = 'translateX(-50%)';
        this.fpsWarningElement.style.background = 'rgba(255, 0, 0, 0.8)';
        this.fpsWarningElement.style.color = 'white';
        this.fpsWarningElement.style.padding = '8px 16px';
        this.fpsWarningElement.style.borderRadius = '4px';
        this.fpsWarningElement.style.fontSize = '14px';
        this.fpsWarningElement.style.fontWeight = 'bold';
        this.fpsWarningElement.style.zIndex = '99999';
        this.fpsWarningElement.style.display = 'none';
        this.fpsWarningElement.style.transition = 'opacity 0.3s ease';
        this.fpsWarningElement.textContent = '您的FPS过低，请及时清理不必要的资源，防止播放器卡顿';
        
        this.container.appendChild(this.fpsWarningElement);
    }
    
    /**
     * 创建控制栏
     */
    createControls() {
        // 创建控制栏容器
        this.controls = document.createElement('div');
        this.controls.className = 'modern-chat-video-controls';
        
        // 创建进度条
        const progressContainer = document.createElement('div');
        progressContainer.className = 'modern-chat-video-progress-container';
        
        this.progressBar = document.createElement('div');
        this.progressBar.className = 'modern-chat-video-progress-bar';
        
        this.progressFill = document.createElement('div');
        this.progressFill.className = 'modern-chat-video-progress-fill';
        
        this.progressBar.appendChild(this.progressFill);
        progressContainer.appendChild(this.progressBar);
        
        // 播放/暂停按钮
        this.playBtn = document.createElement('button');
        this.playBtn.className = 'modern-chat-video-btn modern-chat-video-play-btn';
        this.playBtn.innerHTML = '▶';
        this.playBtn.title = '播放/暂停';
        
        // 音量控制
        const volumeControl = document.createElement('div');
        volumeControl.className = 'modern-chat-video-volume';
        
        this.volumeBtn = document.createElement('button');
        this.volumeBtn.className = 'modern-chat-video-btn';
        this.volumeBtn.innerHTML = '🔊';
        this.volumeBtn.title = '音量';
        
        this.volumeSlider = document.createElement('div');
        this.volumeSlider.className = 'modern-chat-video-volume-slider';
        
        const volumeLevel = document.createElement('div');
        volumeLevel.className = 'modern-chat-video-volume-level';
        this.volumeSlider.appendChild(volumeLevel);
        
        volumeControl.appendChild(this.volumeBtn);
        volumeControl.appendChild(this.volumeSlider);
        
        // 时间显示
        this.timeDisplay = document.createElement('div');
        this.timeDisplay.className = 'modern-chat-video-time';
        this.timeDisplay.textContent = '0:00 / 0:00';
        
        // 下载按钮
        this.downloadBtn = document.createElement('button');
        this.downloadBtn.className = 'modern-chat-video-btn modern-chat-video-download-btn';
        this.downloadBtn.innerHTML = '⬇';
        this.downloadBtn.title = '下载视频';
        
        // 画中画按钮
        this.pipBtn = document.createElement('button');
        this.pipBtn.className = 'modern-chat-video-btn modern-chat-video-pip-btn';
        this.pipBtn.innerHTML = '🖼';
        this.pipBtn.title = '画中画';
        
        // 全屏按钮
        this.fullscreenBtn = document.createElement('button');
        this.fullscreenBtn.className = 'modern-chat-video-btn modern-chat-video-fullscreen-btn';
        this.fullscreenBtn.innerHTML = '⛶';
        this.fullscreenBtn.title = '全屏';
        
        // 组装控制栏
        // 先添加进度条（在按钮上方）
        this.controls.appendChild(progressContainer);
        
        // 创建控制按钮容器
        const controlsRow = document.createElement('div');
        controlsRow.className = 'modern-chat-video-controls-row';
        
        // 左侧控制按钮组（播放/暂停和音量控制）
        const leftControls = document.createElement('div');
        leftControls.className = 'modern-chat-video-left-controls';
        leftControls.appendChild(this.playBtn);
        leftControls.appendChild(volumeControl);
        
        // 中间时间显示
        const centerControls = document.createElement('div');
        centerControls.className = 'modern-chat-video-center-controls';
        centerControls.appendChild(this.timeDisplay);
        
        // 右侧控制按钮组（下载、画中画和全屏）
        const rightControlsGroup = document.createElement('div');
        rightControlsGroup.className = 'modern-chat-video-right-controls';
        rightControlsGroup.appendChild(this.downloadBtn);
        rightControlsGroup.appendChild(this.pipBtn);
        rightControlsGroup.appendChild(this.fullscreenBtn);
        
        controlsRow.appendChild(leftControls);
        controlsRow.appendChild(centerControls);
        controlsRow.appendChild(rightControlsGroup);
        
        this.controls.appendChild(controlsRow);
        
        this.container.appendChild(this.controls);
    }
    
    /**
     * 创建右键菜单
     */
    createContextMenu() {
        this.contextMenu = document.createElement('div');
        this.contextMenu.className = 'modern-chat-video-context-menu';
        
        // 菜单项
        const menuItems = [
            { id: 'stats', label: '视频统计信息' },
            { id: 'fps', label: '视频帧率设置' },
            { id: 'color', label: '视频色彩调整' },
            { id: 'audio', label: '视频音效调节' }
        ];
        
        menuItems.forEach(item => {
            const menuItem = document.createElement('div');
            menuItem.className = 'modern-chat-video-context-menu-item';
            menuItem.dataset.id = item.id;
            menuItem.textContent = item.label;
            
            menuItem.addEventListener('click', () => {
                this.contextMenu.classList.remove('visible');
                this.showModal(item.id);
            });
            
            this.contextMenu.appendChild(menuItem);
        });
        
        // 添加版本信息菜单项
        const versionItem = document.createElement('div');
        versionItem.className = 'modern-chat-video-context-menu-item';
        versionItem.style.color = '#666';
        versionItem.style.fontSize = '12px';
        versionItem.style.paddingTop = '8px';
        versionItem.style.paddingBottom = '8px';
        versionItem.style.borderTop = '1px solid #eee';
        versionItem.style.marginTop = '8px';
        versionItem.textContent = `播放器版本：当前为${PLAYER_VERSION}版本`;
        this.contextMenu.appendChild(versionItem);
        
        document.body.appendChild(this.contextMenu);
    }
    
    /**
     * 创建模态框
     */
    createModals() {
        // 视频统计信息模态框
        this.modals.stats = this.createModal('stats', '视频统计信息', this.createStatsContent());
        
        // 视频帧率设置模态框
        this.modals.fps = this.createModal('fps', '视频帧率设置', this.createFpsContent());
        
        // 视频色彩调整模态框
        this.modals.color = this.createModal('color', '视频色彩调整', this.createColorContent());
        
        // 视频音效调节模态框
        this.modals.audio = this.createModal('audio', '视频音效调节', this.createAudioContent());
    }
    
    /**
     * 创建模态框
     */
    createModal(id, title, content) {
        const modal = document.createElement('div');
        modal.className = 'modern-chat-video-modal';
        modal.id = `modern-chat-video-modal-${id}`;
        
        modal.innerHTML = `
            <div class="modern-chat-video-modal-content">
                <div class="modern-chat-video-modal-header">
                    <h3 class="modern-chat-video-modal-title">${title}</h3>
                    <button class="modern-chat-video-modal-close">&times;</button>
                </div>
                <div class="modern-chat-video-modal-body">
                    ${content}
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // 绑定关闭事件
        const closeBtn = modal.querySelector('.modern-chat-video-modal-close');
        closeBtn.addEventListener('click', () => {
            modal.classList.remove('visible');
        });
        
        // 点击模态框外部关闭
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('visible');
            }
        });
        
        return modal;
    }
    
    /**
     * 创建视频统计信息内容
     */
    createStatsContent() {
        return `
            <div class="modern-chat-video-stats">
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">视频格式:</span>
                    <span class="modern-chat-video-stats-value" id="video-format">${this.getVideoFormat()}</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">视频分辨率:</span>
                    <span class="modern-chat-video-stats-value" id="video-resolution">${this.getVideoResolution()}</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">视频时长:</span>
                    <span class="modern-chat-video-stats-value" id="video-duration">${this.getVideoDuration()}</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">视频大小:</span>
                    <span class="modern-chat-video-stats-value" id="video-size">${this.getVideoSize()}</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">音频大小:</span>
                    <span class="modern-chat-video-stats-value" id="audio-size">${this.getAudioSize()}</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">视频编码:</span>
                    <span class="modern-chat-video-stats-value" id="video-codec">${this.getVideoCodec()}</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">音频编码:</span>
                    <span class="modern-chat-video-stats-value" id="audio-codec">${this.getAudioCodec()}</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">视频速度:</span>
                    <span class="modern-chat-video-stats-value" id="video-speed">0 Kbps</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">音频速度:</span>
                    <span class="modern-chat-video-stats-value" id="audio-speed">0 Kbps</span>
                </div>
                <div class="modern-chat-video-stats-item">
                    <span class="modern-chat-video-stats-label">实时FPS:</span>
                    <span class="modern-chat-video-stats-value" id="video-fps">0 FPS</span>
                </div>
            </div>
        `;
    }
    
    /**
     * 创建视频帧率设置内容
     */
    createFpsContent() {
        return `
            <div class="modern-chat-video-fps-settings">
                <div class="modern-chat-video-fps-option">
                    <label>
                        <input type="radio" name="fps-option" value="30fps" checked>
                        <span>30FPS</span>
                    </label>
                </div>
                <div class="modern-chat-video-fps-option">
                    <label>
                        <input type="radio" name="fps-option" value="60fps">
                        <span>60FPS</span>
                    </label>
                </div>
                <div class="modern-chat-video-fps-option">
                    <label>
                        <input type="radio" name="fps-option" value="120fps">
                        <span>120FPS</span>
                    </label>
                </div>
                <div class="modern-chat-video-fps-option">
                    <label>
                        <input type="radio" name="fps-option" value="auto">
                        <span>自动（视频最高画质）</span>
                    </label>
                </div>
                <div class="modern-chat-video-fps-info">
                    <p>当前视频帧率: <span id="current-fps">0 FPS</span></p>
                    <p>当前设置: <span id="current-fps-mode">30FPS</span></p>
                    <p>推荐码率: <span id="recommended-bitrate">8 Mbps</span></p>
                </div>
            </div>
        `;
    }
    
    /**
     * 创建视频色彩调整内容
     */
    createColorContent() {
        return `
            <div class="modern-chat-video-color-adjust">
                <div class="modern-chat-video-color-control">
                    <div class="modern-chat-video-color-label">
                        <span>亮度</span>
                        <span id="brightness-value">${this.colorSettings.brightness}%</span>
                    </div>
                    <input type="range" class="modern-chat-video-color-slider" id="brightness-slider" min="0" max="200" value="${this.colorSettings.brightness}">
                </div>
                <div class="modern-chat-video-color-control">
                    <div class="modern-chat-video-color-label">
                        <span>对比度</span>
                        <span id="contrast-value">${this.colorSettings.contrast}%</span>
                    </div>
                    <input type="range" class="modern-chat-video-color-slider" id="contrast-slider" min="0" max="200" value="${this.colorSettings.contrast}">
                </div>
                <div class="modern-chat-video-color-control">
                    <div class="modern-chat-video-color-label">
                        <span>饱和度</span>
                        <span id="saturation-value">${this.colorSettings.saturation}%</span>
                    </div>
                    <input type="range" class="modern-chat-video-color-slider" id="saturation-slider" min="0" max="200" value="${this.colorSettings.saturation}">
                </div>
                <div class="modern-chat-video-color-control">
                    <div class="modern-chat-video-color-label">
                        <span>色调</span>
                        <span id="hue-value">${this.colorSettings.hue}°</span>
                    </div>
                    <input type="range" class="modern-chat-video-color-slider" id="hue-slider" min="-180" max="180" value="${this.colorSettings.hue}">
                </div>
                <div class="modern-chat-video-color-control">
                    <div class="modern-chat-video-color-label">
                        <span>灰度</span>
                        <span id="grayscale-value">${this.colorSettings.grayscale}%</span>
                    </div>
                    <input type="range" class="modern-chat-video-color-slider" id="grayscale-slider" min="0" max="100" value="${this.colorSettings.grayscale}">
                </div>
            </div>
        `;
    }
    
    /**
     * 创建视频音效调节内容
     */
    createAudioContent() {
        return `
            <div class="modern-chat-video-audio-adjust">
                <div class="modern-chat-video-audio-control">
                    <div class="modern-chat-video-audio-label">
                        <span>音量</span>
                        <span id="volume-value">${this.audioSettings.volume}%</span>
                    </div>
                    <input type="range" class="modern-chat-video-audio-slider" id="audio-volume-slider" min="0" max="100" value="${this.audioSettings.volume}">
                </div>
                <div class="modern-chat-video-audio-control">
                    <div class="modern-chat-video-audio-label">
                        <span>低音</span>
                        <span id="bass-value">${this.audioSettings.bass}dB</span>
                    </div>
                    <input type="range" class="modern-chat-video-audio-slider" id="bass-slider" min="-20" max="20" value="${this.audioSettings.bass}">
                </div>
                <div class="modern-chat-video-audio-control">
                    <div class="modern-chat-video-audio-label">
                        <span>高音</span>
                        <span id="treble-value">${this.audioSettings.treble}dB</span>
                    </div>
                    <input type="range" class="modern-chat-video-audio-slider" id="treble-slider" min="-20" max="20" value="${this.audioSettings.treble}">
                </div>
                <div class="modern-chat-video-audio-info">
                    <p>360°环绕音效已自动开启</p>
                </div>
            </div>
        `;
    }
    
    /**
     * 绑定事件
     */
    bindEvents() {
        // 播放/暂停事件
        this.playBtn.addEventListener('click', () => this.togglePlay());
        this.video.addEventListener('click', () => this.togglePlay());
        
        // 音量控制事件
        this.volumeBtn.addEventListener('click', () => this.toggleMute());
        this.volumeSlider.addEventListener('click', (e) => {
            const rect = this.volumeSlider.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            this.setVolume(percent * 100);
        });
        
        // 进度条事件
        if (this.progressBar) {
            this.progressBar.addEventListener('click', (e) => {
                const rect = this.progressBar.getBoundingClientRect();
                const percent = (e.clientX - rect.left) / rect.width;
                const duration = this.video.duration || 0;
                const time = duration * percent;
                this.video.currentTime = time;
            });
        }
        
        // 下载按钮事件
        this.downloadBtn.addEventListener('click', () => {
            // 检查是否允许下载视频
            if (!ModernChatVideoPlayerConfig.UseDownload) {
                console.warn('下载功能已禁用');
                return;
            }
            this.downloadVideo();
        });
        
        // 画中画按钮事件
        this.pipBtn.addEventListener('click', () => this.togglePiP());
        
        // 全屏按钮事件
        this.fullscreenBtn.addEventListener('click', () => this.toggleFullscreen());
        
        // 视频事件
        this.video.addEventListener('loadedmetadata', () => this.updateTimeDisplay());
        this.video.addEventListener('durationchange', () => this.updateTimeDisplay());
        this.video.addEventListener('play', () => {
            this.updatePlayButton();
            this.startFpsCalculation();
        });
        this.video.addEventListener('pause', () => {
            this.updatePlayButton();
            this.stopFpsCalculation();
        });
        this.video.addEventListener('volumechange', () => this.updateVolumeDisplay());
        this.video.addEventListener('ended', () => {
            this.updatePlayButton();
            this.stopFpsCalculation();
            // 处理视频播放完成后的清理工作
            this.handleVideoEnded();
        });
        this.video.addEventListener('timeupdate', () => this.updateProgress());
        this.video.addEventListener('error', (e) => {
            console.error('视频播放错误:', e);
            // 处理视频错误，避免控制台持续报错
            this.handleVideoError(e);
        });
        
        // 全屏事件
        this.container.addEventListener('fullscreenchange', () => this.updateFullscreenButton());
        this.container.addEventListener('webkitfullscreenchange', () => this.updateFullscreenButton());
        this.container.addEventListener('mozfullscreenchange', () => this.updateFullscreenButton());
        this.container.addEventListener('msfullscreenchange', () => this.updateFullscreenButton());
        
        // 右键菜单事件 - 绑定到视频元素和容器
        this.video.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.showContextMenu(e.clientX, e.clientY);
        });
        
        this.container.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.showContextMenu(e.clientX, e.clientY);
        });
        
        // 点击其他地方关闭右键菜单
        document.addEventListener('click', (e) => {
            if (!this.contextMenu.contains(e.target)) {
                this.contextMenu.classList.remove('visible');
            }
            
            // 只有当视频已经播放完成时，才释放blob URL
            // 这样可以确保视频在暂停状态下不会因为点击其他地方而停止
            if (!this.container.contains(e.target) && !this.video.contains(e.target) && !this.contextMenu.contains(e.target)) {
                // 检查视频状态 - 只在播放完成时释放
                if (this.video.src && this.video.src.startsWith('blob:') && this.videoCompleted) {
                    // 从活跃blob URL集合中移除
                    if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.activeBlobUrls.has(this.video.src)) {
                        ModernChatVideoPlayer.activeBlobUrls.delete(this.video.src);
                    }
                    // 释放blob URL
                    URL.revokeObjectURL(this.video.src);
                    console.log('已释放播放器的blob URL');
                }
            }
        });
        
        // 鼠标移动事件 - 用于控制栏自动隐藏
        this.container.addEventListener('mousemove', () => this.handleMouseMove());
        this.video.addEventListener('mousemove', () => this.handleMouseMove());
        
        // 模态框控件事件
        this.bindModalEvents();
    }
    
    /**
     * 绑定模态框事件
     */
    bindModalEvents() {
        // 色彩调整滑块事件
        if (this.modals.color) {
            const brightnessSlider = this.modals.color.querySelector('#brightness-slider');
            const contrastSlider = this.modals.color.querySelector('#contrast-slider');
            const saturationSlider = this.modals.color.querySelector('#saturation-slider');
            const hueSlider = this.modals.color.querySelector('#hue-slider');
            const grayscaleSlider = this.modals.color.querySelector('#grayscale-slider');
            
            brightnessSlider.addEventListener('input', (e) => {
                this.colorSettings.brightness = parseInt(e.target.value);
                this.modals.color.querySelector('#brightness-value').textContent = `${this.colorSettings.brightness}%`;
                this.applyColorSettings();
            });
            
            contrastSlider.addEventListener('input', (e) => {
                this.colorSettings.contrast = parseInt(e.target.value);
                this.modals.color.querySelector('#contrast-value').textContent = `${this.colorSettings.contrast}%`;
                this.applyColorSettings();
            });
            
            saturationSlider.addEventListener('input', (e) => {
                this.colorSettings.saturation = parseInt(e.target.value);
                this.modals.color.querySelector('#saturation-value').textContent = `${this.colorSettings.saturation}%`;
                this.applyColorSettings();
            });
            
            hueSlider.addEventListener('input', (e) => {
                this.colorSettings.hue = parseInt(e.target.value);
                this.modals.color.querySelector('#hue-value').textContent = `${this.colorSettings.hue}°`;
                this.applyColorSettings();
            });
            
            grayscaleSlider.addEventListener('input', (e) => {
                this.colorSettings.grayscale = parseInt(e.target.value);
                this.modals.color.querySelector('#grayscale-value').textContent = `${this.colorSettings.grayscale}%`;
                this.applyColorSettings();
            });
        }
        
        // 音效调节滑块事件
        if (this.modals.audio) {
            const volumeSlider = this.modals.audio.querySelector('#audio-volume-slider');
            const bassSlider = this.modals.audio.querySelector('#bass-slider');
            const trebleSlider = this.modals.audio.querySelector('#treble-slider');
            
            volumeSlider.addEventListener('input', (e) => {
                this.audioSettings.volume = parseInt(e.target.value);
                this.modals.audio.querySelector('#volume-value').textContent = `${this.audioSettings.volume}%`;
                this.video.volume = this.audioSettings.volume / 100;
            });
            
            bassSlider.addEventListener('input', (e) => {
                this.audioSettings.bass = parseInt(e.target.value);
                this.modals.audio.querySelector('#bass-value').textContent = `${this.audioSettings.bass}dB`;
                this.applyAudioSettings();
            });
            
            trebleSlider.addEventListener('input', (e) => {
                this.audioSettings.treble = parseInt(e.target.value);
                this.modals.audio.querySelector('#treble-value').textContent = `${this.audioSettings.treble}dB`;
                this.applyAudioSettings();
            });
        }
    }
    
    /**
     * 切换播放/暂停
     */
    togglePlay() {
        // 检查开发者工具是否打开，并且是否禁止开发者工具
        if (window.devToolsOpen && ModernChatVideoPlayerConfig.DeveloperToolsAreProhibited) {
            console.warn('开发者工具被打开，已禁止视频播放');
            return;
        }
        
        if (this.video.paused) {
            // 如果视频已播放完成，需要重新加载
            if (this.videoCompleted) {
                this.videoCompleted = false;
                // 重新加载视频
                this.reloadVideo();
            } else {
                // 正常播放
                this.video.play().catch(error => {
                    console.error('播放视频失败:', error);
                    // 如果播放失败，尝试重新加载
                    this.reloadVideo();
                });
            }
        } else {
            // 暂停视频
            this.video.pause();
        }
    }
    
    /**
     * 重新加载视频
     */
    reloadVideo() {
        try {
            console.log('重新加载视频');
            
            // 确保使用原始视频URL
            if (this.originalVideoUrl) {
                console.log('使用原始视频URL重新加载:', this.originalVideoUrl);
                
                // 清除当前的src
                this.video.src = '';
                this.video.load();
                
                // 使用setTimeout延迟设置新的视频源，确保清理完成
                setTimeout(() => {
                    // 如果是通过MSE播放的视频，需要重新创建blob URL
                    if (ModernChatVideoPlayer.playWithMSE) {
                        ModernChatVideoPlayer.playWithMSE(this.video, this.originalVideoUrl).then(success => {
                            if (success) {
                                console.log('MSE重新加载成功');
                                this.video.play().catch(error => {
                                    console.error('MSE重新加载后播放失败:', error);
                                });
                            } else {
                                console.log('使用传统方式重新加载');
                                // 使用传统方式重新加载
                                this.video.src = this.originalVideoUrl;
                                this.video.load();
                                this.video.play().catch(error => {
                                    console.error('传统方式重新加载播放失败:', error);
                                });
                            }
                        }).catch(error => {
                            console.error('MSE重新加载失败，使用传统方式:', error);
                            // 回退到传统方式
                            this.video.src = this.originalVideoUrl;
                            this.video.load();
                            this.video.play().catch(error2 => {
                                console.error('传统方式重新加载播放失败:', error2);
                            });
                        });
                    } else {
                        // 直接使用传统方式
                        this.video.src = this.originalVideoUrl;
                        this.video.load();
                        this.video.play().catch(error => {
                            console.error('重新加载播放失败:', error);
                        });
                    }
                }, 100);
            } else {
                console.error('原始视频URL不存在，无法重新加载');
            }
        } catch (error) {
            console.error('重新加载视频失败:', error);
        }
    }
    
    /**
     * 切换静音
     */
    toggleMute() {
        this.video.muted = !this.video.muted;
        this.updateVolumeDisplay();
    }
    
    /**
     * 设置音量
     */
    setVolume(volume) {
        this.audioSettings.volume = volume;
        this.video.volume = volume / 100;
        this.video.muted = volume === 0;
        this.updateVolumeDisplay();
        
        // 更新音效调节模态框中的音量值
        if (this.modals.audio) {
            const volumeSlider = this.modals.audio.querySelector('#audio-volume-slider');
            const volumeValue = this.modals.audio.querySelector('#volume-value');
            if (volumeSlider && volumeValue) {
                volumeSlider.value = volume;
                volumeValue.textContent = `${volume}%`;
            }
        }
    }
    
    /**
     * 下载视频
     */
    downloadVideo() {
        if (this.video.src) {
            // 确保视频带有水印
            VideoProtection.addWatermark(this.video);
            
            const a = document.createElement('a');
            a.href = this.video.src;
            a.download = this.getVideoFilename();
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    }
    
    /**
     * 切换画中画
     */
    togglePiP() {
        if (!('pictureInPictureEnabled' in document)) {
            console.warn('画中画功能不受支持');
            return;
        }
        
        if (document.pictureInPictureElement) {
            // 退出画中画
            document.exitPictureInPicture().catch(error => {
                console.error('退出画中画失败:', error);
            });
        } else {
            // 移除可能存在的disablePictureInPicture属性
            if (this.video.hasAttribute('disablePictureInPicture')) {
                this.video.removeAttribute('disablePictureInPicture');
            }
            // 进入画中画
            this.video.requestPictureInPicture().catch(error => {
                console.error('进入画中画失败:', error);
            });
        }
    }
    
    /**
     * 切换全屏
     */
    toggleFullscreen() {
        if (!document.fullscreenElement) {
            // 进入全屏
            const elementToFullscreen = this.container || this.video;
            if (elementToFullscreen.requestFullscreen) {
                elementToFullscreen.requestFullscreen({ navigationUI: 'hide' });
            } else if (elementToFullscreen.webkitRequestFullscreen) {
                elementToFullscreen.webkitRequestFullscreen();
            } else if (elementToFullscreen.mozRequestFullScreen) {
                elementToFullscreen.mozRequestFullScreen();
            } else if (elementToFullscreen.msRequestFullscreen) {
                elementToFullscreen.msRequestFullscreen();
            }
        } else {
            // 退出全屏
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        }
    }
    
    /**
     * 显示右键菜单
     */
    showContextMenu(x, y) {
        this.contextMenu.style.left = `${x}px`;
        this.contextMenu.style.top = `${y}px`;
        this.contextMenu.classList.add('visible');
    }
    
    /**
     * 显示模态框
     */
    showModal(id) {
        if (this.modals[id]) {
            // 更新统计信息
            if (id === 'stats') {
                this.updateStats();
                
                // 开始实时更新视频和音频速度
                this.startSpeedUpdate();
                
                // 开始FPS计算，即使视频没有播放也能显示实时帧率
                this.startFpsCalculation();
            } else if (id === 'fps') {
                // 更新FPS设置模态框的显示
                this.updateFpsSettings();
                
                // 绑定FPS选项点击事件
                this.bindFpsEvents();
                
                // 开始FPS计算，确保能显示实时FPS
                this.startFpsCalculation();
            }
            
            this.modals[id].classList.add('visible');
        }
    }
    
    /**
     * 开始实时更新视频和音频速度
     */
    startSpeedUpdate() {
        // 清除之前的更新计时器
        if (this.speedUpdateInterval) {
            clearInterval(this.speedUpdateInterval);
        }
        
        // 重置FPS计数，确保从0开始
        this.fpsFrameCount = 0;
        this.fpsLastTime = Date.now();
        
        // 设置新的更新计时器，每1秒更新一次
        this.speedUpdateInterval = setInterval(() => {
            this.updateSpeedStats();
        }, 1000);
        
        // 监听模态框关闭事件
        if (this.modals.stats) {
            // 清除之前的关闭事件监听器
            if (this.statsCloseListener) {
                this.modals.stats.removeEventListener('click', this.statsCloseListener);
            }
            
            // 添加新的关闭事件监听器
            this.statsCloseListener = (e) => {
                if (e.target === this.modals.stats || e.target.classList.contains('modern-chat-video-modal-close')) {
                    this.stopSpeedUpdate();
                }
            };
            
            this.modals.stats.addEventListener('click', this.statsCloseListener);
        }
    }
    
    /**
     * 停止实时更新视频和音频速度
     */
    stopSpeedUpdate() {
        if (this.speedUpdateInterval) {
            clearInterval(this.speedUpdateInterval);
            this.speedUpdateInterval = null;
        }
        
        // 停止FPS计算
        this.stopFpsCalculation();
    }
    
    /**
     * 开始计算FPS
     */
    startFpsCalculation() {
        if (this.fpsIntervalId) {
            cancelAnimationFrame(this.fpsIntervalId);
        }
        
        this.fpsFrameCount = 0;
        this.fpsLastTime = Date.now();
        
        // 使用requestAnimationFrame来准确计算FPS，并实现FPS锁定
        let frameTimestamp = 0;
        let lastFrameTime = 0;
        
        const calculateFps = (timestamp) => {
            // 计算帧间隔时间
            if (lastFrameTime === 0) {
                lastFrameTime = timestamp;
            }
            const deltaTime = timestamp - lastFrameTime;
            lastFrameTime = timestamp;
            
            // 根据当前FPS模式计算目标帧间隔
            let targetFrameInterval = 1000 / 60; // 默认60FPS
            if (this.fpsMode === '30fps') {
                targetFrameInterval = 1000 / 30;
            } else if (this.fpsMode === '60fps') {
                targetFrameInterval = 1000 / 60;
            } else if (this.fpsMode === '120fps') {
                targetFrameInterval = 1000 / 120;
            }
            
            // 只有当达到目标帧间隔时才计数
            if (deltaTime >= targetFrameInterval) {
                this.fpsFrameCount++;
            }
            
            this.fpsIntervalId = requestAnimationFrame(calculateFps);
        };
        
        this.fpsIntervalId = requestAnimationFrame(calculateFps);
    }
    
    /**
     * 停止计算FPS
     */
    stopFpsCalculation() {
        if (this.fpsIntervalId) {
            cancelAnimationFrame(this.fpsIntervalId);
            this.fpsIntervalId = null;
        }
    }
    
    /**
     * 处理鼠标移动事件
     */
    handleMouseMove() {
        // 只有在全屏模式下才启用自动隐藏
        if (this.isFullscreen()) {
            // 重置鼠标移动计时器
            this.resetMouseMoveTimer();
            
            // 如果控制栏已隐藏，显示它
            if (!this.isControlsVisible) {
                this.showControls();
            }
        }
    }
    
    /**
     * 重置鼠标移动计时器
     */
    resetMouseMoveTimer() {
        // 清除之前的计时器
        if (this.mouseMoveTimer) {
            clearTimeout(this.mouseMoveTimer);
        }
        
        // 设置新的计时器，5秒后隐藏控制栏
        this.mouseMoveTimer = setTimeout(() => {
            this.hideControls();
        }, 5000);
    }
    
    /**
     * 显示控制栏
     */
    showControls() {
        if (this.controls) {
            this.controls.style.opacity = '1';
            this.controls.style.visibility = 'visible';
            this.controls.style.transition = 'opacity 0.3s ease, visibility 0s';
            this.isControlsVisible = true;
        }
    }
    
    /**
     * 隐藏控制栏
     */
    hideControls() {
        if (this.controls && this.isFullscreen()) {
            this.controls.style.opacity = '0';
            this.controls.style.visibility = 'hidden';
            this.controls.style.transition = 'opacity 0.3s ease, visibility 0s 0.3s';
            this.isControlsVisible = false;
        }
    }
    
    /**
     * 检查是否处于全屏模式
     */
    isFullscreen() {
        return !!(document.fullscreenElement || 
                document.webkitFullscreenElement || 
                document.mozFullScreenElement || 
                document.msFullscreenElement);
    }
    
    /**
     * 更新播放按钮状态
     */
    updatePlayButton() {
        if (this.video.paused) {
            this.playBtn.innerHTML = '▶';
        } else {
            this.playBtn.innerHTML = '⏸';
        }
    }
    
    /**
     * 更新音量显示
     */
    updateVolumeDisplay() {
        if (this.video.muted) {
            this.volumeBtn.innerHTML = '🔇';
        } else if (this.video.volume < 0.3) {
            this.volumeBtn.innerHTML = '🔈';
        } else if (this.video.volume < 0.7) {
            this.volumeBtn.innerHTML = '🔉';
        } else {
            this.volumeBtn.innerHTML = '🔊';
        }
        
        // 更新音量滑块
        if (this.volumeSlider) {
            const volumeLevel = this.volumeSlider.querySelector('.modern-chat-video-volume-level');
            if (volumeLevel) {
                const volume = this.video.muted ? 0 : this.video.volume * 100;
                volumeLevel.style.width = `${volume}%`;
            }
        }
    }
    
    /**
     * 更新全屏按钮状态
     */
    updateFullscreenButton() {
        const isFullscreen = this.isFullscreen();
        
        if (isFullscreen) {
            this.fullscreenBtn.innerHTML = '⛶';
            this.fullscreenBtn.title = '退出全屏';
            
            // 进入全屏模式，重置鼠标移动计时器
            this.resetMouseMoveTimer();
        } else {
            this.fullscreenBtn.innerHTML = '⛶';
            this.fullscreenBtn.title = '全屏';
            
            // 退出全屏模式，停止计时器并显示控制栏
            if (this.mouseMoveTimer) {
                clearTimeout(this.mouseMoveTimer);
                this.mouseMoveTimer = null;
            }
            this.showControls();
        }
    }
    
    /**
     * 更新时间显示
     */
    updateTimeDisplay() {
        const currentTime = this.formatTime(this.video.currentTime);
        const duration = this.formatTime(this.video.duration);
        this.timeDisplay.textContent = `${currentTime} / ${duration}`;
    }
    
    /**
     * 更新进度条
     */
    updateProgress() {
        if (this.progressFill && this.video.duration > 0) {
            const percent = (this.video.currentTime / this.video.duration) * 100;
            this.progressFill.style.width = `${percent}%`;
        }
        this.updateTimeDisplay();
    }
    
    /**
     * 格式化时间
     */
    formatTime(seconds) {
        if (isNaN(seconds)) return '0:00';
        
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
    
    /**
     * 应用色彩设置
     */
    applyColorSettings() {
        const { brightness, contrast, saturation, hue, grayscale } = this.colorSettings;
        
        this.video.style.filter = `
            brightness(${brightness}%) 
            contrast(${contrast}%) 
            saturate(${saturation}%) 
            hue-rotate(${hue}deg) 
            grayscale(${grayscale}%)
        `;
    }
    
    /**
     * 应用音效设置
     */
    applyAudioSettings() {
        // 使用Web Audio API实现音效控制
        if (!this.audioContext) {
            try {
                // 创建音频上下文
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                // 创建媒体元素源
                this.source = this.audioContext.createMediaElementSource(this.video);
                // 创建增益节点用于音量控制
                this.gainNode = this.audioContext.createGain();
                // 创建均衡器节点用于低音和高音控制
                this.bassFilter = this.audioContext.createBiquadFilter();
                this.bassFilter.type = 'lowshelf';
                this.bassFilter.frequency.value = 250; // 低音频率
                
                this.trebleFilter = this.audioContext.createBiquadFilter();
                this.trebleFilter.type = 'highshelf';
                this.trebleFilter.frequency.value = 4000; // 高音频率
                
                // 创建3D环绕音效节点
                this.pannerNode = this.audioContext.createPanner();
                this.pannerNode.panningModel = 'HRTF'; // 使用头部相关传输函数，提供更真实的3D效果
                this.pannerNode.distanceModel = 'linear'; // 线性距离模型，更明显的距离衰减
                this.pannerNode.refDistance = 1; // 参考距离
                this.pannerNode.maxDistance = 10; // 最大距离，减少到10以增强衰减效果
                this.pannerNode.rolloffFactor = 2; // 增加衰减因子，增强方向性
                this.pannerNode.coneInnerAngle = 60; // 锥体内角60度，只有正前方声音最大
                this.pannerNode.coneOuterAngle = 180; // 锥体外角180度
                this.pannerNode.coneOuterGain = 0.1; // 锥体外部增益降低到0.1，增强方向性差异
                
                // 创建监听者节点
                this.listener = this.audioContext.listener;
                
                // 初始连接音频节点
                this.updateAudioGraph();
            } catch (error) {
                console.warn('Web Audio API not supported:', error);
                return;
            }
        }
        
        // 应用音量设置
        if (this.gainNode) {
            this.gainNode.gain.value = this.audioSettings.volume / 100;
        }
        
        // 应用低音设置
        if (this.bassFilter) {
            this.bassFilter.gain.value = this.audioSettings.bass;
        }
        
        // 应用高音设置
        if (this.trebleFilter) {
            this.trebleFilter.gain.value = this.audioSettings.treble;
        }
        
        // 应用360°环绕音效设置
        if (this.pannerNode) {
            // 更新音频图连接
            this.updateAudioGraph();
            
            // 自动启动360°环绕音效
            this.startSpatialAudioEffect();
        }
    }
    
    /**
     * 更新音频图连接
     */
    updateAudioGraph() {
        if (!this.audioContext || !this.source) return;
        
        // 断开所有连接
        this.source.disconnect();
        this.bassFilter.disconnect();
        this.trebleFilter.disconnect();
        this.pannerNode.disconnect();
        this.gainNode.disconnect();
        
        // 始终使用360°环绕音效
        this.source.connect(this.bassFilter);
        this.bassFilter.connect(this.trebleFilter);
        this.trebleFilter.connect(this.pannerNode);
        this.pannerNode.connect(this.gainNode);
        this.gainNode.connect(this.audioContext.destination);
    }
    
    /**
     * 启动空间音频效果
     */
    startSpatialAudioEffect() {
        if (!this.pannerNode) return;
        
        // 清除之前的动画
        if (this.spatialAudioAnimation) {
            cancelAnimationFrame(this.spatialAudioAnimation);
        }
        
        let angle = 0;
        const radius = 2; // 增加半径以增强环绕效果
        const intensity = 0.95; // 增加强度以增强环绕效果
        
        // 360°环绕音效动画函数（音频围绕听者旋转）
        const animate = () => {
            // 计算新的位置：音频源在三维空间中环绕听者旋转
            // 水平旋转（方位角）
            const horizontalAngle = angle;
            // 垂直旋转（俯仰角），添加垂直方向变化
            const verticalAngle = Math.sin(angle * 0.5) * 0.5;
            
            const x = Math.sin(horizontalAngle) * Math.cos(verticalAngle) * radius * intensity;
            const y = Math.sin(verticalAngle) * radius * intensity * 0.5; // 垂直方向幅度较小
            const z = Math.cos(horizontalAngle) * Math.cos(verticalAngle) * radius * intensity;
            
            // 更新音频源位置
            this.pannerNode.positionX.value = x;
            this.pannerNode.positionY.value = y;
            this.pannerNode.positionZ.value = z;
            
            // 设置音频源始终面向听者（原点），这样左右声道会有明显差异
            this.pannerNode.orientationX.value = -x; // 指向原点的方向
            this.pannerNode.orientationY.value = -y;
            this.pannerNode.orientationZ.value = -z;
            
            // 更新角度：环绕速度设置为5（5倍速）
            angle += 0.15;
            if (angle > Math.PI * 2) {
                angle = 0;
            }
            
            // 继续动画
            this.spatialAudioAnimation = requestAnimationFrame(animate);
        };
        
        // 开始动画
        this.spatialAudioAnimation = requestAnimationFrame(animate);
    }
    
    /**
     * 停止空间音频效果
     */
    stopSpatialAudioEffect() {
        if (this.spatialAudioAnimation) {
            cancelAnimationFrame(this.spatialAudioAnimation);
            this.spatialAudioAnimation = null;
        }
        
        // 重置音频源位置
        if (this.pannerNode) {
            this.pannerNode.positionX.value = 0;
            this.pannerNode.positionY.value = 0;
            this.pannerNode.positionZ.value = 0;
        }
    }
    
    /**
     * 更新统计信息
     */
    updateStats() {
        if (this.modals.stats) {
            this.modals.stats.querySelector('#video-format').textContent = this.getVideoFormat();
            this.modals.stats.querySelector('#video-resolution').textContent = this.getVideoResolution();
            this.modals.stats.querySelector('#video-duration').textContent = this.getVideoDuration();
            this.modals.stats.querySelector('#video-size').textContent = this.getVideoSize();
            this.modals.stats.querySelector('#audio-size').textContent = this.getAudioSize();
            this.modals.stats.querySelector('#video-codec').textContent = this.getVideoCodec();
            this.modals.stats.querySelector('#audio-codec').textContent = this.getAudioCodec();
        }
    }
    
    /**
     * 更新视频和音频速度
     */
    updateSpeedStats() {
        // 计算并更新FPS（无论统计窗口是否打开）
        const now = Date.now();
        const elapsed = now - this.fpsLastTime;
        
        if (elapsed >= 1000) {
            this.currentFps = Math.round((this.fpsFrameCount * 1000) / elapsed);
            this.fpsLastTime = now;
            this.fpsFrameCount = 0;
            
            // 检测FPS警告
            this.checkFpsWarning();
        }
        
        // 更新统计窗口显示
        if (this.modals.stats && this.modals.stats.classList.contains('visible')) {
            // 尝试获取视频速度
            let videoSpeed = 0;
            let audioSpeed = 0;
            
            // 使用Performance API和视频事件来估算速度
            if (this.video.buffered.length > 0) {
                const bufferedEnd = this.video.buffered.end(this.video.buffered.length - 1);
                const currentTime = this.video.currentTime;
                
                // 简单估算：基于已缓冲数据和播放时间
                if (currentTime > 0) {
                    // 假设视频数据量与时间成正比
                    // 这里使用一个估算值，实际应用中可能需要更复杂的计算
                    videoSpeed = Math.round((bufferedEnd * 1000) / (currentTime + 1));
                    audioSpeed = Math.round(videoSpeed * 0.2); // 假设音频速度约为视频的20%
                }
            }
            
            // 更新显示
            this.modals.stats.querySelector('#video-speed').textContent = `${videoSpeed} Kbps`;
            this.modals.stats.querySelector('#audio-speed').textContent = `${audioSpeed} Kbps`;
            this.modals.stats.querySelector('#video-fps').textContent = `${this.currentFps} FPS`;
        }
    }
    
    /**
     * 检测FPS警告并显示/隐藏警告提示
     */
    checkFpsWarning() {
        if (this.currentFps< 10) {
            this.showFpsWarning();
        } else {
            this.hideFpsWarning();
        }
    }
    
    /**
     * 显示FPS警告
     */
    showFpsWarning() {
        if (this.fpsWarningElement) {
            this.fpsWarningElement.style.display = 'block';
            this.fpsWarningElement.style.opacity = '1';
        }
    }
    
    /**
     * 隐藏FPS警告
     */
    hideFpsWarning() {
        if (this.fpsWarningElement) {
            this.fpsWarningElement.style.opacity = '0';
            setTimeout(() => {
                this.fpsWarningElement.style.display = 'none';
            }, 300);
        }
    }
    
    /**
     * 更新FPS设置模态框的显示
     */
    updateFpsSettings() {
        const modal = this.modals.fps;
        if (modal) {
            // 更新当前FPS显示
            const currentFpsEl = modal.querySelector('#current-fps');
            if (currentFpsEl) {
                currentFpsEl.textContent = `${this.currentFps} FPS`;
            }
            
            // 更新当前设置显示
            const currentModeEl = modal.querySelector('#current-fps-mode');
            if (currentModeEl) {
                let modeText = '30FPS';
                if (this.fpsMode === '60fps') modeText = '60FPS';
                else if (this.fpsMode === '120fps') modeText = '120FPS';
                else if (this.fpsMode === 'auto') modeText = '自动';
                currentModeEl.textContent = modeText;
            }
            
            // 更新推荐码率显示
            const bitrateEl = modal.querySelector('#recommended-bitrate');
            if (bitrateEl) {
                const recommendedBitrate = this.getRecommendedBitrate();
                bitrateEl.textContent = `${recommendedBitrate} Mbps`;
            }
            
            // 更新单选按钮状态
            const radioButtons = modal.querySelectorAll('input[name="fps-option"]');
            radioButtons.forEach(radio => {
                radio.checked = radio.value === this.fpsMode;
            });
        }
    }
    
    /**
     * 绑定FPS选项点击事件
     */
    bindFpsEvents() {
        const modal = this.modals.fps;
        if (modal) {
            const radioButtons = modal.querySelectorAll('input[name="fps-option"]');
            radioButtons.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    const selectedMode = e.target.value;
                    this.setFpsMode(selectedMode);
                });
            });
        }
    }
    
    /**
     * 设置FPS模式
     */
    setFpsMode(mode) {
        this.fpsMode = mode;
        
        // 设置对应的FPS限制
        if (mode === '30fps') {
            this.fpsLimit = 30;
        } else if (mode === '60fps') {
            this.fpsLimit = 60;
        } else if (mode === '120fps') {
            this.fpsLimit = 120;
        } else if (mode === 'auto') {
            this.fpsLimit = Infinity; // 无限制，使用视频最高帧率
        }
        
        // 更新显示
        this.updateFpsSettings();
        
        // 应用码率控制
        this.applyBitrateControl();
    }
    
    /**
     * 根据分辨率和FPS获取推荐的最高码率
     */
    getRecommendedBitrate() {
        if (!this.video.videoWidth || !this.video.videoHeight) {
            return 8; // 默认8 Mbps
        }
        
        const width = this.video.videoWidth;
        const height = this.video.videoHeight;
        const fps = this.fpsLimit !== Infinity ? this.fpsLimit : 60; // 自动模式默认按60FPS计算
        
        // 根据分辨率和FPS计算推荐码率（Mbps）
        if (width <= 1920 && height <= 1080) {
            // 1080p
            if (fps <= 30) {
                return 8; // 8 Mbps
            } else {
                return 12; // 12 Mbps
            }
        } else if (width <= 2560 && height <= 1440) {
            // 1440p (2K)
            if (fps <= 30) {
                return 15; // 15 Mbps
            } else {
                return 25; // 25 Mbps
            }
        } else {
            // 4K及以上
            if (fps <= 30) {
                return 35; // 35 Mbps
            } else {
                return 50; // 50 Mbps
            }
        }
    }
    
    /**
     * 应用码率控制防止卡顿
     */
    applyBitrateControl() {
        const recommendedBitrate = this.getRecommendedBitrate();
        
        // 对于直接播放的视频，通过调整视频质量来控制码率
        if (this.video.playbackRate) {
            // 根据推荐码率调整播放速率
            const currentBitrate = this.estimateCurrentBitrate();
            if (currentBitrate > recommendedBitrate && this.video.playbackRate > 0.9) {
                // 如果当前码率超过推荐码率，稍微降低播放速率
                this.video.playbackRate = Math.max(0.9, this.video.playbackRate - 0.05);
            } else if (currentBitrate< recommendedBitrate && this.video.playbackRate <1.0) {
                // 如果当前码率低于推荐码率，恢复正常播放速率
                this.video.playbackRate = 1.0;
            }
        }
        // 注意：不再通过重新加载视频来应用码率控制，避免出现"Empty src attribute"错误
    }
    
    /**
     * 估算当前视频码率
     */
    estimateCurrentBitrate() {
        if (!this.video.buffered.length || this.video.currentTime === 0) {
            return 0;
        }
        
        const bufferedEnd = this.video.buffered.end(this.video.buffered.length - 1);
        const currentTime = this.video.currentTime;
        const bufferedDuration = bufferedEnd - currentTime;
        
        // 假设视频数据量与时间成正比，估算码率
        return Math.round((bufferedDuration * 1000) / (currentTime + 1));
    }
    
    /**
     * 获取视频格式
     */
    getVideoFormat() {
        const src = this.video.src;
        if (src) {
            // 尝试从URL中提取扩展名
            const ext = src.split('.').pop().split('?')[0].toLowerCase();
            const knownFormats = {
                'mp4': 'MP4',
                'mov': 'MOV',
                'm4v': 'M4V',
                '3gp': '3GP',
                '3g2': '3G2',
                'avi': 'AVI',
                'wmv': 'WMV',
                'asf': 'ASF',
                'flv': 'FLV',
                'f4v': 'F4V',
                'swf': 'SWF',
                'mkv': 'MKV',
                'webm': 'WebM',
                'ogg': 'Ogg',
                'mpg': 'MPG',
                'mpeg': 'MPEG',
                'mts': 'MTS',
                'm2ts': 'M2TS',
                'ts': 'TS',
                'vob': 'VOB',
                'evo': 'EVO',
                'mod': 'MOD',
                'tod': 'TOD',
                'mxf': 'MXF',
                'gxf': 'GXF',
                'dv': 'DV',
                'dvr-ms': 'DVR-MS',
                'amv': 'AMV',
                'rm': 'RM',
                'rmvb': 'RMVB',
                'dat': 'DAT'
            };
            if (knownFormats[ext]) {
                return knownFormats[ext];
            }
            
            // 尝试从视频元素的type属性获取
            if (this.video.type) {
                const mimeType = this.video.type;
                if (mimeType.includes('mp4')) return 'MP4';
                if (mimeType.includes('quicktime')) return 'MOV';
                if (mimeType.includes('matroska')) return 'MKV';
                if (mimeType.includes('webm')) return 'WebM';
                if (mimeType.includes('avi')) return 'AVI';
                if (mimeType.includes('3gpp')) return '3GP';
                if (mimeType.includes('3gpp2')) return '3G2';
                if (mimeType.includes('wmv')) return 'WMV';
                if (mimeType.includes('asf')) return 'ASF';
                if (mimeType.includes('flv')) return 'FLV';
                if (mimeType.includes('ogg')) return 'Ogg';
                if (mimeType.includes('mpeg')) return 'MPEG';
                if (mimeType.includes('mp2t')) return 'TS';
                if (mimeType.includes('dv')) return 'DV';
                if (mimeType.includes('realmedia')) return 'RM';
            }
        }
        return 'MP4'; // 默认返回MP4
    }
    
    /**
     * 获取视频分辨率
     */
    getVideoResolution() {
        if (this.video.videoWidth && this.video.videoHeight) {
            return `${this.video.videoWidth} × ${this.video.videoHeight}`;
        }
        return '未知';
    }
    
    /**
     * 获取视频时长
     */
    getVideoDuration() {
        if (!isNaN(this.video.duration)) {
            return this.formatTime(this.video.duration);
        }
        return '未知';
    }
    
    /**
     * 获取视频大小
     */
    getVideoSize() {
        // 尝试从视频元素的属性获取
        if (this.video.duration && this.video.bitrate) {
            // 估算视频大小：比特率 * 时长
            const sizeInBytes = (this.video.bitrate * this.video.duration) / 8;
            return this.formatFileSize(sizeInBytes);
        }
        
        // 尝试从网络请求获取
        if (this.video.src && !this.video.src.startsWith('blob:')) {
            // 注意：这是一个异步操作，不会立即返回结果
            // 但我们可以在后台尝试获取并更新UI
            fetch(this.video.src, { method: 'HEAD' })
                .then(response => {
                    if (response.ok && response.headers.get('content-length')) {
                        const size = parseInt(response.headers.get('content-length'));
                        const sizeText = this.formatFileSize(size);
                        // 更新UI
                        if (this.modals && this.modals.stats) {
                            const sizeElement = this.modals.stats.querySelector('#video-size');
                            if (sizeElement) {
                                sizeElement.textContent = sizeText;
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('获取视频大小失败:', error);
                });
        }
        
        // 尝试使用视频速度和时长估算
        if (this.video.duration) {
            // 假设视频速度为1000Kbps
            const videoBitrate = 1000 * 1000; // 1000Kbps
            const sizeInBytes = (videoBitrate * this.video.duration) / 8;
            return this.formatFileSize(sizeInBytes);
        }
        
        return '未知';
    }
    
    /**
     * 获取音频大小
     */
    getAudioSize() {
        // 音频大小通常是视频大小的一部分，难以单独获取
        // 这里我们可以估算一个值
        if (this.video.duration) {
            // 假设音频比特率为128kbps
            const audioBitrate = 128000;
            const sizeInBytes = (audioBitrate * this.video.duration) / 8;
            return this.formatFileSize(sizeInBytes);
        }
        
        return '未知';
    }
    
    /**
     * 格式化文件大小
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    /**
     * 获取视频编码
     */
    getVideoCodec() {
        // 尝试使用 videoTracks API 获取编码信息
        if (this.video.videoTracks && this.video.videoTracks.length > 0) {
            const videoTrack = this.video.videoTracks[0];
            if (videoTrack.label) {
                return videoTrack.label;
            }
            if (videoTrack.kind) {
                return videoTrack.kind;
            }
        }
        
        // 尝试从视频元素的属性获取
        if (this.video.codecs) {
            return this.video.codecs;
        }
        
        // 尝试从视频格式推断
        const format = this.getVideoFormat();
        const codecsByFormat = {
            'MP4': 'H.264',
            'MOV': 'H.264',
            'MKV': 'H.264',
            'WEBM': 'VP8/VP9',
            'AVI': 'MPEG-4'
        };
        if (codecsByFormat[format]) {
            return codecsByFormat[format];
        }
        
        return 'H.264'; // 默认返回H.264
    }
    
    /**
     * 获取音频编码
     */
    getAudioCodec() {
        // 尝试使用 audioTracks API 获取编码信息
        if (this.video.audioTracks && this.video.audioTracks.length > 0) {
            const audioTrack = this.video.audioTracks[0];
            if (audioTrack.label) {
                return audioTrack.label;
            }
            if (audioTrack.kind) {
                return audioTrack.kind;
            }
        }
        
        // 尝试从视频元素的属性获取
        if (this.video.codecs) {
            return this.video.codecs;
        }
        
        return 'AAC'; // 默认返回AAC
    }
    
    /**
     * 获取视频文件名
     */
    getVideoFilename() {
        const src = this.video.src;
        if (src) {
            const filename = src.split('/').pop().split('?')[0];
            return filename;
        }
        return 'video.mp4';
    }
    
    /**
     * 处理视频播放完成
     */
    handleVideoEnded() {
        try {
            // 确保视频已暂停
            if (!this.video.paused) {
                this.video.pause();
            }
            
            // 重置播放按钮状态
            this.updatePlayButton();
            
            // 记录视频已播放完成
            this.videoCompleted = true;
            
            // 释放当前blob链接
            const currentSrc = this.video.src;
            if (currentSrc && currentSrc.startsWith('blob:')) {
                // 检查全局releaseBlobUrl函数是否存在
                if (typeof releaseBlobUrl === 'function') {
                    releaseBlobUrl(currentSrc);
                } else {
                    // 如果全局函数不存在，使用内部方法释放
                    this.releaseBlobUrl(currentSrc);
                }
                console.log('视频播放完成，已释放blob链接:', currentSrc);
            } else if (currentSrc) {
                console.log('视频播放完成，使用的是非blob链接:', currentSrc);
            }
            
            // 重置原始视频元素的_playModalShown标志，允许再次点击播放按钮
            if (this.originalVideo) {
                this.originalVideo._playModalShown = false;
                console.log('视频播放完成，已重置原始视频元素的_playModalShown标志');
            }
            
            // 不清除视频元素的src，而是在reloadVideo中直接设置
            // 这样可以避免出现Empty src attribute错误
            console.log('视频播放完成，保持视频元素状态');
        } catch (error) {
            console.error('处理视频播放完成时出错:', error);
        }
    }
    
    /**
     * 释放blob URL
     */
    releaseBlobUrl(blobUrl) {
        if (blobUrl && blobUrl.startsWith('blob:')) {
            // 检查是否是假的blob URL
            if (blobUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                // 获取对应的真实blob URL
                const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(blobUrl);
                if (realUrl && realUrl.startsWith('blob:')) {
                    // 释放真实的blob URL
                    if (ModernChatVideoPlayer.activeBlobUrls) {
                        ModernChatVideoPlayer.activeBlobUrls.delete(realUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlMap) {
                        ModernChatVideoPlayer.blobUrlMap.delete(realUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlCreationTime) {
                        ModernChatVideoPlayer.blobUrlCreationTime.delete(realUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlContext) {
                        ModernChatVideoPlayer.blobUrlContext.delete(realUrl);
                    }
                    if (window.__deactivateBlobUrl) {
                        window.__deactivateBlobUrl(realUrl);
                    }
                    try {
                        URL.revokeObjectURL(realUrl);
                        console.log('已释放真实blob URL:', realUrl);
                    } catch (error) {
                        console.warn('释放真实blob URL失败:', error);
                    }
                }
                // 从假blob URL映射中移除
                if (ModernChatVideoPlayer.fakeBlobMap) {
                    ModernChatVideoPlayer.fakeBlobMap.delete(blobUrl);
                }
                console.log('已释放假blob URL:', blobUrl);
            } else {
                // 释放真实的blob URL
                if (ModernChatVideoPlayer.activeBlobUrls) {
                    ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                }
                if (ModernChatVideoPlayer.blobUrlMap) {
                    ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                }
                if (ModernChatVideoPlayer.blobUrlCreationTime) {
                    ModernChatVideoPlayer.blobUrlCreationTime.delete(blobUrl);
                }
                if (ModernChatVideoPlayer.blobUrlContext) {
                    ModernChatVideoPlayer.blobUrlContext.delete(blobUrl);
                }
                if (window.__deactivateBlobUrl) {
                    window.__deactivateBlobUrl(blobUrl);
                }
                try {
                    URL.revokeObjectURL(blobUrl);
                    console.log('已释放blob URL:', blobUrl);
                } catch (error) {
                    console.warn('释放blob URL失败:', error);
                }
            }
        }
    }
    
    /**
     * 处理视频错误
     */
    handleVideoError(error) {
        try {
            // 记录错误但不持续报错
            console.error('视频播放错误:', error);
            
            // 确保视频已暂停
            if (!this.video.paused) {
                this.video.pause();
            }
            
            // 重置播放按钮状态
            this.updatePlayButton();
            
            // 处理NoSupportedError错误，尝试重新加载视频
            if (error && (error.name === 'NoSupportedError' || error.message.includes('no supported source was found'))) {
                console.log('处理NoSupportedError错误，尝试重新加载视频');
                // 延迟一段时间后重新加载视频
                setTimeout(() => {
                    this.reloadVideo();
                }, 1000);
            }
        } catch (error) {
            // 防止错误处理本身出错
        }
    }
    
    /**
     * 销毁播放器
     */
    destroy() {
        // 停止速度更新
        this.stopSpeedUpdate();
        
        // 清理鼠标移动计时器
        if (this.mouseMoveTimer) {
            clearTimeout(this.mouseMoveTimer);
            this.mouseMoveTimer = null;
        }
        
        // 移除事件监听器
        if (this.modals.stats && this.statsCloseListener) {
            this.modals.stats.removeEventListener('click', this.statsCloseListener);
        }
        
        // 移除DOM元素
        if (this.contextMenu && this.contextMenu.parentNode) {
            this.contextMenu.parentNode.removeChild(this.contextMenu);
        }
        
        Object.values(this.modals).forEach(modal => {
            if (modal && modal.parentNode) {
                modal.parentNode.removeChild(modal);
            }
        });
        
        // 重置视频状态标志
        this.videoCompleted = false;
        
        // 恢复原始视频元素
        if (this.container && this.video) {
            this.container.parentNode.insertBefore(this.video, this.container);
            this.container.parentNode.removeChild(this.container);
        }
    }
}

// 播放器版本
ModernChatVideoPlayer.VERSION = PLAYER_VERSION;

/**
 * 根据文件扩展名获取视频MIME类型
 */
ModernChatVideoPlayer.getVideoMimeType = function(url) {
    const extension = url.split('.').pop().toLowerCase().split('?')[0];
    const mimeTypes = {
        'mp4': 'video/mp4',
        'mov': 'video/quicktime',
        'm4v': 'video/mp4',
        '3gp': 'video/3gpp',
        '3g2': 'video/3gpp2',
        'avi': 'video/avi',
        'wmv': 'video/x-ms-wmv',
        'asf': 'video/x-ms-asf',
        'flv': 'video/x-flv',
        'f4v': 'video/mp4',
        'swf': 'application/x-shockwave-flash',
        'mkv': 'video/x-matroska',
        'webm': 'video/webm',
        'ogg': 'video/ogg',
        'mpg': 'video/mpeg',
        'mpeg': 'video/mpeg',
        'mts': 'video/MP2T',
        'm2ts': 'video/MP2T',
        'ts': 'video/MP2T',
        'vob': 'video/dvd',
        'evo': 'video/MP2T',
        'mod': 'video/mpeg',
        'tod': 'video/mpeg',
        'mxf': 'application/mxf',
        'gxf': 'application/gxf',
        'dv': 'video/dv',
        'dvr-ms': 'video/x-ms-dvr',
        'hevc': 'video/hevc',
        'h265': 'video/hevc',
        'hvc1': 'video/hevc',
        'h265mp4': 'video/hevc',
        'hevcmp4': 'video/hevc',
        'hevcmp4v': 'video/hevc',
        'h265mp4v': 'video/hevc',
        'amv': 'video/x-amv',
        'rm': 'application/vnd.rn-realmedia',
        'rmvb': 'application/vnd.rn-realmedia-vbr',
        'dat': 'video/mpeg'
    };
    return mimeTypes[extension] || null;
};

/**
 * 初始化所有Modern Chat Video Player
 */
ModernChatVideoPlayer.initAll = function() {
    // 增强网络请求保护，隐藏真实文件路径
    ModernChatVideoPlayer.enhanceNetworkProtection();
    
    const videos = document.querySelectorAll('video[data-modern-player]');
    
    // 使用requestAnimationFrame来优化DOM操作，避免页面卡死
    function processVideos(index) {
        if (index >= videos.length) {
            return;
        }
        
        const video = videos[index];
        
        // 检查视频是否已经初始化
        if (video._playerInitialized) {
            requestAnimationFrame(() => processVideos(index + 1));
            return;
        }
        
        // 标记视频为已初始化
        video._playerInitialized = true;
        
        // 检查是否已经存在包装元素
        let wrapper = video.parentElement;
        if (!wrapper.classList.contains('modern-chat-video-wrapper')) {
            // 创建包装元素
            wrapper = document.createElement('div');
            wrapper.className = 'modern-chat-video-wrapper';
            wrapper.style.position = 'relative';
            wrapper.style.display = 'inline-block';
            wrapper.style.width = '100%';
            wrapper.style.maxWidth = '300px';
            wrapper.style.height = '200px';
            
            // 将视频元素移动到包装元素中
            video.parentNode.insertBefore(wrapper, video);
            wrapper.appendChild(video);
        }
        
        // 检查是否已经存在播放按钮覆盖层
        if (!wrapper.querySelector('.modern-chat-video-play-overlay')) {
            // 创建播放按钮覆盖层
            const playOverlay = document.createElement('div');
            playOverlay.className = 'modern-chat-video-play-overlay';
            playOverlay.style.position = 'absolute';
            playOverlay.style.top = '0';
            playOverlay.style.left = '0';
            playOverlay.style.width = '100%';
            playOverlay.style.height = '100%';
            playOverlay.style.background = 'rgba(0, 0, 0, 0.5)';
            playOverlay.style.display = 'flex';
            playOverlay.style.alignItems = 'center';
            playOverlay.style.justifyContent = 'center';
            playOverlay.style.cursor = 'pointer';
            playOverlay.style.opacity = '0.8';
            playOverlay.style.transition = 'opacity 0.3s ease';
            playOverlay.style.borderRadius = '8px';
            playOverlay.style.zIndex = '10';
            
            // 创建播放按钮
            const playButton = document.createElement('div');
            playButton.style.width = '60px';
            playButton.style.height = '60px';
            playButton.style.background = 'rgba(0, 0, 0, 0.7)';
            playButton.style.borderRadius = '50%';
            playButton.style.display = 'flex';
            playButton.style.alignItems = 'center';
            playButton.style.justifyContent = 'center';
            playButton.style.fontSize = '30px';
            playButton.style.color = 'white';
            playButton.style.opacity = '0.9';
            playButton.innerHTML = '▶';
            
            playOverlay.appendChild(playButton);
            wrapper.appendChild(playOverlay);
            
            // 为覆盖层添加点击事件，点击后弹出播放弹窗
            playOverlay.addEventListener('click', function() {
                const video = this.parentNode.querySelector('video');
                if (video && !video._playModalShown) {
                    video._playModalShown = true;
                    // 弹出播放弹窗
                    ModernChatVideoPlayer.showPlayModal(video);
                }
            });
        }
        
        // 设置视频为封面模式，只显示首帧
        video.setAttribute('poster', video.src);
        video.setAttribute('preload', 'metadata');
        video.setAttribute('controls', 'false');
        video.style.cursor = 'pointer';
        video.style.borderRadius = '8px';
        video.style.width = '100%';
        video.style.height = '100%';
        video.style.objectFit = 'cover';
        
        // 继续处理下一个视频
        requestAnimationFrame(() => processVideos(index + 1));
    }
    
    // 开始处理视频
    processVideos(0);
};

/**
 * 为所有播放按钮覆盖层添加点击事件
 */
ModernChatVideoPlayer.bindPlayButtonEvents = function() {
    // 使用requestAnimationFrame来优化DOM操作，避免页面卡死
    requestAnimationFrame(function() {
        const overlays = document.querySelectorAll('.modern-chat-video-play-overlay');
        
        function processOverlays(index) {
            if (index >= overlays.length) {
                return;
            }
            
            const overlay = overlays[index];
            
            // 移除已有的点击事件监听器，避免重复添加
            const newOverlay = overlay.cloneNode(true);
            overlay.parentNode.replaceChild(newOverlay, overlay);
            
            // 添加新的点击事件监听器
            newOverlay.addEventListener('click', function() {
                const video = this.parentNode.querySelector('video');
                if (video && !video._playModalShown) {
                    video._playModalShown = true;
                    // 弹出播放弹窗
                    ModernChatVideoPlayer.showPlayModal(video);
                }
            });
            
            // 继续处理下一个覆盖层
            requestAnimationFrame(() => processOverlays(index + 1));
        }
        
        // 开始处理覆盖层
        processOverlays(0);
    });
};

// 存储当前正在使用的blob URL
    ModernChatVideoPlayer.activeBlobUrls = new Set();
    
    // 为每个视频创建唯一的会话ID
    ModernChatVideoPlayer.sessionId = Math.random().toString(36).substring(2, 11);
    
    // 存储视频元素与blob URL的映射
    ModernChatVideoPlayer.videoBlobMap = new WeakMap();
    
    // 存储blob URL与原始URL的映射
    ModernChatVideoPlayer.blobUrlMap = new Map();
    
    // 存储blob URL的创建时间
    ModernChatVideoPlayer.blobUrlCreationTime = new Map();
    
    // 存储blob URL的访问上下文
    ModernChatVideoPlayer.blobUrlContext = new Map();
    
    /**
     * 检查本地是否有缓存的视频文件
     */
    ModernChatVideoPlayer.checkLocalCache = function(videoUrl, callback) {
        // 尝试从IndexedDB获取缓存信息
        try {
            // 检查是否存在indexedDBManager（与缓存逻辑一致）
            if (typeof indexedDBManager !== 'undefined') {
                indexedDBManager.getFile(videoUrl)
                    .then(function(fileData) {
                        if (fileData && fileData.blob) {
                            // 缓存有效，创建Blob URL
                            const realBlobUrl = URL.createObjectURL(fileData.blob);
                            // 生成假的blob链接
                            const fakeBlobUrl = ModernChatVideoPlayer.generateFakeBlobUrl(realBlobUrl);
                            // 添加真实blob URL到活跃blob URL集合
                            ModernChatVideoPlayer.activeBlobUrls.add(realBlobUrl);
                            // 激活blob URL
                            if (window.__activateBlobUrl) {
                                window.__activateBlobUrl(realBlobUrl);
                            }
                            // 存储blob URL与原始URL的映射
                            ModernChatVideoPlayer.blobUrlMap.set(realBlobUrl, videoUrl);
                            // 存储blob URL的创建时间
                            ModernChatVideoPlayer.blobUrlCreationTime.set(realBlobUrl, Date.now());
                            // 存储blob URL的访问上下文
                            ModernChatVideoPlayer.blobUrlContext.set(realBlobUrl, {
                                type: 'cache',
                                timestamp: Date.now(),
                                referrer: document.referrer || window.location.href
                            });
                            // 设置blob URL的过期时间（5分钟）
                            setTimeout(function() {
                                if (ModernChatVideoPlayer.activeBlobUrls.has(realBlobUrl)) {
                                    ModernChatVideoPlayer.activeBlobUrls.delete(realBlobUrl);
                                    ModernChatVideoPlayer.blobUrlMap.delete(realBlobUrl);
                                    ModernChatVideoPlayer.blobUrlCreationTime.delete(realBlobUrl);
                                    ModernChatVideoPlayer.blobUrlContext.delete(realBlobUrl);
                                    ModernChatVideoPlayer.fakeBlobMap.delete(fakeBlobUrl);
                                    // 停用blob URL
                                    if (window.__deactivateBlobUrl) {
                                        window.__deactivateBlobUrl(realBlobUrl);
                                    }
                                    URL.revokeObjectURL(realBlobUrl);
                                    console.log('Blob URL已过期并释放:', realBlobUrl);
                                }
                            }, 5 * 60 * 1000);
                            callback(true, fakeBlobUrl);
                        } else if (fileData && fileData.data) {
                            // 兼容旧格式，生成假的blob链接
                            const fakeBlobUrl = ModernChatVideoPlayer.generateFakeBlobUrl(fileData.data);
                            callback(true, fakeBlobUrl);
                        } else {
                            // 没有缓存
                            callback(false);
                        }
                    })
                    .catch(function(error) {
                        console.error('从IndexedDB获取视频缓存失败:', error);
                        // 即使出错也返回false，继续使用原始URL
                        callback(false);
                    });
            } else {
                // 没有IndexedDB支持，返回false
                callback(false);
            }
        } catch (e) {
            console.error('检查本地缓存失败:', e);
            callback(false);
        }
    };
    
    /**
     * 增强网络请求保护，隐藏真实文件路径
     */
    ModernChatVideoPlayer.enhanceNetworkProtection = function() {
        console.log('正在增强网络请求保护...');
        
        // 方法1：监控Performance API，隐藏网络请求信息
        if (window.PerformanceObserver) {
            const observer = new PerformanceObserver(function(list) {
                const entries = list.getEntries();
                entries.forEach(function(entry) {
                    // 检查是否是网络请求
                    if (entry.entryType === 'resource' && entry.name) {
                        // 检查是否是视频文件
                        if (entry.name.includes('.mp4') || entry.name.includes('.mov') || entry.name.includes('.mkv') || entry.name.includes('.webm') || entry.name.includes('.avi')) {
                            // 尝试隐藏真实文件路径，只显示文件名
                            const parts = entry.name.split('/');
                            const fileName = parts[parts.length - 1];
                            // 移除日志输出，避免控制台过于混乱
                            // console.log('检测到视频网络请求，已隐藏真实路径:', fileName);
                        }
                    }
                });
            });
            
            observer.observe({ entryTypes: ['resource'] });
        }
        
        // 方法2：重写console.log，防止泄露网络信息
        const originalLog = console.log;
        console.log = function(...args) {
            // 检查参数是否包含网络请求信息
            const filteredArgs = args.map(arg => {
                if (typeof arg === 'string' && (arg.includes('.mp4') || arg.includes('.mov') || arg.includes('.mkv') || arg.includes('.webm') || arg.includes('.avi'))) {
                    // 隐藏真实文件路径，只显示文件名
                    const parts = arg.split('/');
                    const fileName = parts[parts.length - 1];
                    return '[视频文件] ' + fileName;
                }
                return arg;
            });
            originalLog.apply(filteredArgs);
        };
        
        // 方法3：重写console.warn，防止泄露网络信息
        const originalWarn = console.warn;
        console.warn = function(...args) {
            // 检查参数是否包含网络请求信息
            const filteredArgs = args.map(arg => {
                if (typeof arg === 'string' && (arg.includes('.mp4') || arg.includes('.mov') || arg.includes('.mkv') || arg.includes('.webm') || arg.includes('.avi'))) {
                    // 隐藏真实文件路径，只显示文件名
                    const parts = arg.split('/');
                    const fileName = parts[parts.length - 1];
                    return '[视频文件] ' + fileName;
                }
                return arg;
            });
            originalWarn.apply(console, filteredArgs);
        };
        
        // 方法4：重写console.error，防止泄露网络信息
        const originalError = console.error;
        console.error = function(...args) {
            // 检查参数是否包含网络请求信息
            const filteredArgs = args.map(arg => {
                if (typeof arg === 'string' && (arg.includes('.mp4') || arg.includes('.mov') || arg.includes('.mkv') || arg.includes('.webm') || arg.includes('.avi'))) {
                    // 隐藏真实文件路径，只显示文件名
                    const parts = arg.split('/');
                    const fileName = parts[parts.length - 1];
                    return '[视频文件] ' + fileName;
                }
                return arg;
            });
            originalError.apply(console, filteredArgs);
        };
        
        // 方法5：监控网络请求，隐藏真实URL
        if (window.fetch) {
            const originalFetch = window.fetch;
            window.fetch = function(url, _options) {
                    // 检查是否是视频文件请求
                    if (typeof url === 'string' && (url.includes('.mp4') || url.includes('.mov') || url.includes('.mkv') || url.includes('.webm') || url.includes('.avi'))) {
                        // 隐藏真实文件路径，只显示文件名
                        const parts = url.split('/');
                        const fileName = parts[parts.length - 1];
                        console.log('拦截到视频文件请求，已隐藏真实URL:', fileName);
                    }
                    return originalFetch.apply(this, arguments);
            };
        }
        
        // 方法6：监控XMLHttpRequest，隐藏真实URL
        if (window.XMLHttpRequest) {
            const originalOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function(_method, url, _async, _user, _password) {
                    // 检查是否是视频文件请求
                    if (typeof url === 'string' && (url.includes('.mp4') || url.includes('.mov') || url.includes('.mkv') || url.includes('.webm') || url.includes('.avi'))) {
                        // 隐藏真实文件路径，只显示文件名
                        const parts = url.split('/');
                        const fileName = parts[parts.length - 1];
                        console.log('拦截到视频文件XMLHttpRequest请求，已隐藏真实URL:', fileName);
                    }
                    return originalOpen.apply(this, arguments);
            };
        }
        
        // 方法7：使用Service Worker拦截网络请求
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('service-worker.js').catch(function(error) {
                console.warn('Service Worker注册失败:', error);
            });
        }
        
        console.log('网络请求保护增强完成');
    };
    
    // 添加全局导航拦截器，防止通过blob URL获取原视频
    function interceptBlobNavigation() {
        const currentUrl = window.location.href;
        if (currentUrl.startsWith('blob:')) {
            // 显示错误信息
            document.body.innerHTML = `
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; background: #f0f0f0;">
                    <h1 style="color: #333;">未找到文件</h1>
                    <p style="color: #666;">它可能已被移动、编辑或删除。</p>
                </div>
            `;
            return true;
        }
        return false;
    }
    
    // 监听popstate事件（浏览器前进/后退）
    window.addEventListener('popstate', function(e) {
        if (interceptBlobNavigation()) {
            e.preventDefault();
        }
    });
    
    // 监听hashchange事件（URL哈希变化）
    window.addEventListener('hashchange', function(e) {
        if (interceptBlobNavigation()) {
            e.preventDefault();
        }
    });
    
    // 页面加载时检查
    window.addEventListener('load', function() {
        interceptBlobNavigation();
    });
    
    // 页面可见性变化时检查
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            interceptBlobNavigation();
        }
    });
    
    // 定期清理过期的blob URL
    setInterval(function() {
        // 清理过期的blob URL
        if (ModernChatVideoPlayer.activeBlobUrls && ModernChatVideoPlayer.blobUrlCreationTime) {
            const now = Date.now();
            const expirationTime = 5 * 60 * 1000; // 5分钟过期
            
            // 检查每个活跃的blob URL
            for (const blobUrl of ModernChatVideoPlayer.activeBlobUrls) {
                const creationTime = ModernChatVideoPlayer.blobUrlCreationTime.get(blobUrl);
                if (creationTime && now - creationTime > expirationTime) {
                    // 检查blob URL是否还在使用中
                    let isInUse = false;
                    
                    // 检查所有视频元素是否在使用这个blob URL
                    document.querySelectorAll('video').forEach(video => {
                        if (video.src === blobUrl) {
                            isInUse = true;
                        }
                    });
                    
                    // 如果不在使用中，释放blob URL
                    if (!isInUse) {
                        try {
                            URL.revokeObjectURL(blobUrl);
                            ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                            ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                            ModernChatVideoPlayer.blobUrlCreationTime.delete(blobUrl);
                            ModernChatVideoPlayer.blobUrlContext.delete(blobUrl);
                            console.log('定期清理：已释放过期的blob URL:', blobUrl);
                        } catch (error) {
                            console.warn('定期清理：释放blob URL失败:', error);
                        }
                    }
                }
            }
        }
    }, 30000);
    
    // 实现强大的blob URL保护机制
    (function() {
        // 生成唯一的窗口标识符
        const windowId = Math.random().toString(36).substr(2, 15);
        
        // 存储所有创建的blob URL
        const createdBlobUrls = new Set();
        
        // 重写URL.createObjectURL方法
        const originalCreateObjectURL = URL.createObjectURL;
        URL.createObjectURL = function(object) {
            const blobUrl = originalCreateObjectURL.call(this, object);
            
            // 记录创建的blob URL
            createdBlobUrls.add(blobUrl);
            
            // 初始时将blob URL标记为非活跃
            if (ModernChatVideoPlayer.activeBlobUrls) {
                ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
            }
            
            // 存储blob URL的创建上下文
            if (ModernChatVideoPlayer.blobUrlContext) {
                ModernChatVideoPlayer.blobUrlContext.set(blobUrl, {
                    type: 'created',
                    timestamp: Date.now(),
                    origin: window.location.origin,
                    windowId: windowId,
                    referrer: document.referrer || window.location.href,
                    createdInWindow: windowId
                });
            }
            
            // 将blob URL与当前窗口关联
            if (!window._modernChatBlobUrls) {
                window._modernChatBlobUrls = new Set();
            }
            window._modernChatBlobUrls.add(blobUrl);
            
            return blobUrl;
        };
        
        // 重写URL.revokeObjectURL方法
        const originalRevokeObjectURL = URL.revokeObjectURL;
        URL.revokeObjectURL = function(url) {
            // 从创建的blob URL集合中删除
            createdBlobUrls.delete(url);
            if (ModernChatVideoPlayer.activeBlobUrls) {
                ModernChatVideoPlayer.activeBlobUrls.delete(url);
            }
            if (ModernChatVideoPlayer.blobUrlMap) {
                ModernChatVideoPlayer.blobUrlMap.delete(url);
            }
            if (ModernChatVideoPlayer.blobUrlCreationTime) {
                ModernChatVideoPlayer.blobUrlCreationTime.delete(url);
            }
            if (ModernChatVideoPlayer.blobUrlContext) {
                ModernChatVideoPlayer.blobUrlContext.delete(url);
            }
            return originalRevokeObjectURL.call(this, url);
        };
        
        // 监控所有网络请求
        if (window.fetch) {
            const originalFetch = window.fetch;
            window.fetch = function(url, _options) {
                if (typeof url === 'string' && url.startsWith('blob:')) {
                    // 检查是否是本窗口创建的blob URL
                    if (!createdBlobUrls.has(url)) {
                        return Promise.reject(new Error('Access denied: blob URL was not created in this window'));
                    }
                    // 检查是否是同源请求
                    if (window.location.origin && !url.includes(window.location.origin)) {
                        return Promise.reject(new Error('Access denied: blob URL can only be accessed from same-origin pages'));
                    }
                    // 检查blob URL是否活跃
                    if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(url)) {
                        return Promise.reject(new Error('Access denied: blob URL not active'));
                    }
                    // 检查是否是从本页面发起的请求
                    const stack = new Error().stack;
                    if (stack && !stack.includes('ModernChatVideoPlayer') && !stack.includes('createPlayModal') && !stack.includes('reloadVideo') && !stack.includes('VideoProtection.playWithMSE') && !stack.includes('playWithMSE') && !stack.includes('VideoProtection') && !stack.includes('fetchVideoSegments')) {
                        return Promise.reject(new Error('Access denied: only Modern Chat Video Player can access this blob URL'));
                    }
                }
                return originalFetch.apply(this, arguments);
            };
        }
        
        // 监控XMLHttpRequest
        if (window.XMLHttpRequest) {
            const originalOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function(_method, url, _async, _user, _password) {
                if (typeof url === 'string' && url.startsWith('blob:')) {
                    // 检查是否是本窗口创建的blob URL
                    if (!createdBlobUrls.has(url)) {
                        throw new Error('Access denied: blob URL was not created in this window');
                    }
                    // 检查是否是同源请求
                    if (window.location.origin && !url.includes(window.location.origin)) {
                        throw new Error('Access denied: blob URL can only be accessed from same-origin pages');
                    }
                    // 检查blob URL是否活跃
                    if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(url)) {
                        throw new Error('Access denied: blob URL not active');
                    }
                    // 检查是否是从本页面发起的请求
                    const stack = new Error().stack;
                    if (stack && !stack.includes('ModernChatVideoPlayer') && !stack.includes('createPlayModal') && !stack.includes('reloadVideo') && !stack.includes('VideoProtection.playWithMSE') && !stack.includes('playWithMSE') && !stack.includes('VideoProtection') && !stack.includes('fetchVideoSegments')) {
                        throw new Error('Access denied: only Modern Chat Video Player can access this blob URL');
                    }
                }
                return originalOpen.apply(this, arguments);
            };
        }
        
        // 监控页面加载时的blob URL访问
        window.addEventListener('load', function() {
            if (window.location.href.startsWith('blob:')) {
                // 直接阻止所有blob URL在新标签页中的访问
                // 因为在新标签页中，播放器上下文不存在
                document.body.innerHTML = '<h1>Access denied</h1><p>Blob URL can only be accessed through Modern Chat Video Player interface</p>';
                return;
            }
        });
        
        // 立即检查，不等待load事件
        if (window.location.href.startsWith('blob:')) {
            // 直接阻止所有blob URL在新标签页中的访问
            document.body.innerHTML = '<h1>Access denied</h1><p>Blob URL can only be accessed through Modern Chat Video Player interface</p>';
        }
        
        // 定期清理非活跃的blob URL
        setInterval(function() {
            if (ModernChatVideoPlayer.activeBlobUrls) {
                const now = Date.now();
                for (const blobUrl of ModernChatVideoPlayer.activeBlobUrls) {
                    const context = ModernChatVideoPlayer.blobUrlContext.get(blobUrl);
                    if (context && (now - context.timestamp > 3600000)) { // 1小时过期
                        ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                        URL.revokeObjectURL(blobUrl);
                        console.log('Expired blob URL revoked:', blobUrl);
                    }
                }
            }
        }, 60000); // 每分钟检查一次
        
        // 监控视频元素的创建和使用
        const originalCreateElement = document.createElement;
        document.createElement = function(tagName) {
            const element = originalCreateElement.call(this, tagName);
            if (tagName.toLowerCase() === 'video') {
                // 监控视频元素的播放方法
                const originalPlay = element.play;
                element.play = function() {
                    const src = this.src;
                    if (src && src.startsWith('blob:')) {
                        // 检查是否是本窗口创建的blob URL
                        if (!createdBlobUrls.has(src)) {
                            return Promise.reject(new Error('Access denied: blob URL was not created in this window'));
                        }
                        // 检查是否是同源请求
                        if (window.location.origin && !src.includes(window.location.origin)) {
                            return Promise.reject(new Error('Access denied: blob URL can only be accessed from same-origin pages'));
                        }
                        // 检查blob URL是否活跃
                        if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(src)) {
                            return Promise.reject(new Error('Access denied: blob URL not active'));
                        }
                    }
                    return originalPlay.apply(this, arguments);
                };
                
                // 监控视频元素的src属性
                const originalSrc = Object.getOwnPropertyDescriptor(HTMLVideoElement.prototype, 'src');
                if (originalSrc && originalSrc.set) {
                    Object.defineProperty(element, 'src', {
                        get: originalSrc.get,
                        set: function(value) {
                            if (typeof value === 'string' && value.startsWith('blob:')) {
                                // 检查是否是本窗口创建的blob URL
                                if (!createdBlobUrls.has(value)) {
                                    throw new Error('Access denied: blob URL was not created in this window');
                                }
                                // 检查blob URL是否活跃
                                if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(value)) {
                                    // 检查是否是MSE创建的blob URL
                                    const stack = new Error().stack;
                                    if (stack && (stack.includes('VideoProtection.playWithMSE') || stack.includes('playWithMSE'))) {
                                        // 允许MSE播放时的blob URL访问
                                    } else {
                                        throw new Error('Access denied: blob URL not active');
                                    }
                                }
                                // 检查是否是从本页面发起的请求
                                const stack = new Error().stack;
                                if (stack && !stack.includes('ModernChatVideoPlayer') && !stack.includes('createPlayModal') && !stack.includes('reloadVideo') && !stack.includes('VideoProtection.playWithMSE') && !stack.includes('playWithMSE')) {
                                    throw new Error('Access denied: only Modern Chat Video Player can access this blob URL');
                                }
                                // 检查是否是同源请求
                                if (window.location.origin && !value.includes(window.location.origin)) {
                                    throw new Error('Access denied: blob URL can only be accessed from same-origin pages');
                                }
                            }
                            originalSrc.set.call(this, value);
                        }
                    });
                }
            }
            return element;
        };
        
        // 提供方法来激活和停用blob URL
        window.__activateBlobUrl = function(blobUrl) {
            if (ModernChatVideoPlayer.activeBlobUrls) {
                ModernChatVideoPlayer.activeBlobUrls.add(blobUrl);
            }
        };
        
        window.__deactivateBlobUrl = function(blobUrl) {
            if (ModernChatVideoPlayer.activeBlobUrls) {
                ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
            }
        };
    })();
    
    // 注册Service Worker以拦截blob URL请求
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('service-worker.js')
                .then(function(registration) {
                    console.log('Service Worker 注册成功:', registration.scope);
                })
                .catch(function(error) {
                    console.log('Service Worker 注册失败:', error);
                });
        });
    }

/**
 * 缓存视频文件
 */
ModernChatVideoPlayer.cacheVideo = function(videoUrl, callback) {
    // 创建缓存进度条
    const progressBar = ModernChatVideoPlayer.createCacheProgressBar(videoUrl);
    
    // 尝试从服务器获取视频并缓存到IndexedDB
    try {
        // 检查是否存在saveFileToIndexedDB函数
        if (typeof saveFileToIndexedDB === 'function') {
            // 从服务器获取视频，使用streaming模式获取进度
            fetch(videoUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'video/mp4,video/quicktime,video/x-matroska,video/webm,video/avi,video/*'
                }
            })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    
                    // 获取视频总大小
                    const contentLength = parseInt(response.headers.get('content-length') || '0');
                    let receivedLength = 0;
                    const chunks = [];
                    
                    // 读取响应流
                    const reader = response.body.getReader();
                    
                    function read() {
                        return reader.read().then(function(result) {
                            if (result.done) {
                                // 读取完成，合并chunks为blob
                                const contentType = response.headers.get('content-type');
                                // 根据文件扩展名推断MIME类型
                                const inferredType = ModernChatVideoPlayer.getVideoMimeType(videoUrl);
                                const finalType = contentType || inferredType || 'video/mp4';
                                const blob = new Blob(chunks, { type: finalType });
                                return blob;
                            }
                            
                            // 读取到新的chunk
                            chunks.push(result.value);
                            receivedLength += result.value.length;
                            
                            // 更新进度条
                            if (contentLength > 0) {
                                const progress = Math.round((receivedLength / contentLength) * 100);
                                ModernChatVideoPlayer.updateCacheProgressBar(progressBar, progress);
                            }
                            
                            // 继续读取
                            return read();
                        });
                    }
                    
                    // 开始读取
                    return read();
                })
                .then(function(blob) {
                    // 创建Blob URL
                    const blobUrl = URL.createObjectURL(blob);
                    // 添加到活跃blob URL集合
                    ModernChatVideoPlayer.activeBlobUrls.add(blobUrl);
                    // 存储blob URL与原始URL的映射
                    ModernChatVideoPlayer.blobUrlMap.set(blobUrl, videoUrl);
                    // 设置blob URL的过期时间（5分钟）
                    setTimeout(function() {
                        if (ModernChatVideoPlayer.activeBlobUrls.has(blobUrl)) {
                            ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                            ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                            URL.revokeObjectURL(blobUrl);
                            console.log('Blob URL已过期并释放:', blobUrl);
                        }
                    }, 5 * 60 * 1000);
                    
                    // 缓存到IndexedDB
                    const fileData = {
                        id: videoUrl, // 添加id字段作为IndexedDB的key
                        path: videoUrl,
                        blob: blob,
                        type: blob.type,
                        size: blob.size,
                        timestamp: new Date().toISOString()
                    };
                    
                    saveFileToIndexedDB(fileData)
                        .then(function() {
                            console.log('视频缓存成功:', videoUrl);
                            // 移除进度条
                            ModernChatVideoPlayer.removeCacheProgressBar(progressBar);
                            callback(blobUrl);
                        })
                        .catch(function(error) {
                            console.error('缓存视频到IndexedDB失败:', error);
                            // 移除进度条
                            ModernChatVideoPlayer.removeCacheProgressBar(progressBar);
                            // 即使缓存失败也返回blobUrl，继续播放
                            callback(blobUrl);
                        });
                })
                .catch(function(error) {
                    console.error('获取视频失败:', error);
                    // 移除进度条
                    ModernChatVideoPlayer.removeCacheProgressBar(progressBar);
                    // 即使获取失败也返回原始URL，继续尝试
                    callback(videoUrl);
                });
        } else {
            // 没有IndexedDB支持，直接使用原始URL
            ModernChatVideoPlayer.removeCacheProgressBar(progressBar);
            callback(videoUrl);
        }
    } catch (e) {
        console.error('缓存视频失败:', e);
        // 移除进度条
        ModernChatVideoPlayer.removeCacheProgressBar(progressBar);
        // 即使出错也返回原始URL，继续尝试
        callback(videoUrl);
    }
};

/**
 * 创建缓存进度条
 */
ModernChatVideoPlayer.createCacheProgressBar = function(videoUrl) {
    // 创建进度条容器
    const container = document.createElement('div');
    container.className = 'modern-chat-cache-progress-container';
    container.style.position = 'fixed';
    container.style.bottom = '20px';
    container.style.left = '50%';
    container.style.transform = 'translateX(-50%)';
    container.style.width = '80%';
    container.style.maxWidth = '600px';
    container.style.background = 'rgba(0, 0, 0, 0.8)';
    container.style.borderRadius = '8px';
    container.style.padding = '15px';
    container.style.zIndex = '999999999';
    container.style.color = 'white';
    container.style.fontSize = '14px';
    container.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.3)';
    
    // 创建标题
    const title = document.createElement('div');
    title.className = 'modern-chat-cache-progress-title';
    title.style.marginBottom = '10px';
    title.style.display = 'flex';
    title.style.justifyContent = 'space-between';
    title.style.alignItems = 'center';
    
    // 标题文本
    const titleText = document.createElement('span');
    titleText.textContent = '正在缓存视频...';
    
    // 关闭按钮
    const closeBtn = document.createElement('button');
    closeBtn.className = 'modern-chat-cache-progress-close';
    closeBtn.style.background = 'none';
    closeBtn.style.border = 'none';
    closeBtn.style.color = 'white';
    closeBtn.style.fontSize = '18px';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.padding = '0';
    closeBtn.style.width = '24px';
    closeBtn.style.height = '24px';
    closeBtn.style.display = 'flex';
    closeBtn.style.alignItems = 'center';
    closeBtn.style.justifyContent = 'center';
    closeBtn.style.borderRadius = '50%';
    closeBtn.style.transition = 'background 0.2s ease';
    closeBtn.innerHTML = '&times;';
    
    // 点击关闭按钮停止缓存
    closeBtn.addEventListener('click', function() {
        // 这里可以添加停止缓存的逻辑
        ModernChatVideoPlayer.removeCacheProgressBar(container);
    });
    
    title.appendChild(titleText);
    title.appendChild(closeBtn);
    
    // 创建进度条容器
    const progressContainer = document.createElement('div');
    progressContainer.className = 'modern-chat-cache-progress-bar-container';
    progressContainer.style.width = '100%';
    progressContainer.style.height = '8px';
    progressContainer.style.background = 'rgba(255, 255, 255, 0.2)';
    progressContainer.style.borderRadius = '4px';
    progressContainer.style.overflow = 'hidden';
    
    // 创建进度条
    const progressBar = document.createElement('div');
    progressBar.className = 'modern-chat-cache-progress-bar';
    progressBar.style.width = '0%';
    progressBar.style.height = '100%';
    progressBar.style.background = '#1976d2';
    progressBar.style.borderRadius = '4px';
    progressBar.style.transition = 'width 0.3s ease';
    
    progressContainer.appendChild(progressBar);
    
    // 创建进度文本
    const progressText = document.createElement('div');
    progressText.className = 'modern-chat-cache-progress-text';
    progressText.style.marginTop = '8px';
    progressText.style.fontSize = '12px';
    progressText.style.color = 'rgba(255, 255, 255, 0.8)';
    progressText.style.textAlign = 'center';
    progressText.textContent = '0%';
    
    // 组装进度条
    container.appendChild(title);
    container.appendChild(progressContainer);
    container.appendChild(progressText);
    
    // 添加到页面
    document.body.appendChild(container);
    
    // 返回进度条容器，包含所有必要的元素
    return {
        container: container,
        progressBar: progressBar,
        progressText: progressText
    };
};

/**
 * 更新缓存进度条
 */
ModernChatVideoPlayer.updateCacheProgressBar = function(progressBar, progress) {
    if (progressBar && progressBar.container) {
        progressBar.progressBar.style.width = `${progress}%`;
        progressBar.progressText.textContent = `${progress}%`;
    }
};

/**
 * 移除缓存进度条
 */
ModernChatVideoPlayer.removeCacheProgressBar = function(progressBar) {
    if (progressBar && progressBar.container && progressBar.container.parentNode) {
        progressBar.container.parentNode.removeChild(progressBar.container);
    }
};

/**
 * 显示播放弹窗
 */
ModernChatVideoPlayer.showPlayModal = function(video) {
    // 确保DOM已经完全加载
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            ModernChatVideoPlayer.showPlayModal(video);
        });
        return;
    }
    
    // 检查本地是否有缓存的视频文件
        ModernChatVideoPlayer.checkLocalCache(video.src, function(hasCache, cachedUrl) {
            let videoUrl = video.src;
            
            if (hasCache) {
                // 使用本地缓存的文件
                videoUrl = cachedUrl;
                console.log('使用本地缓存的视频文件');
                // 创建弹窗容器
                createPlayModal(video, videoUrl);
            } else {
                // 缓存视频文件
                console.log('视频文件未缓存，开始缓存:', video.src);
                ModernChatVideoPlayer.cacheVideo(video.src, async function(cachedUrl) {
                    if (cachedUrl) {
                        videoUrl = cachedUrl;
                    }
                    
                    // 创建弹窗容器
                    await createPlayModal(video, videoUrl);
                });
            }
        });
        
        // 为页面添加视频保护脚本
        (function() {
            // 禁用右键菜单
            document.addEventListener('contextmenu', function(e) {
                if (e.target.tagName === 'VIDEO' || e.target.closest('.modern-chat-video-player')) {
                    e.preventDefault();
                }
            });
            
            // 禁用键盘快捷键
            document.addEventListener('keydown', function(e) {
                // 禁用Ctrl+S（保存）
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                }
                // 禁用F12（开发者工具）
                if (e.key === 'F12') {
                    e.preventDefault();
                }
                // 禁用Ctrl+Shift+I（开发者工具）
                if ((e.ctrlKey || e.shiftKey) && e.key === 'I') {
                    e.preventDefault();
                }
            });
            
            // 存储假blob链接到真实URL的映射
            ModernChatVideoPlayer.fakeBlobMap = new Map();
            
            // 生成假的blob链接
            ModernChatVideoPlayer.generateFakeBlobUrl = function(realUrl) {
                const fakeBlobUrl = `blob:https://${window.location.hostname}/fake-${Math.random().toString(36).substr(2, 15)}`;
                ModernChatVideoPlayer.fakeBlobMap.set(fakeBlobUrl, realUrl);
                return fakeBlobUrl;
            };
            
            // 监控网络请求，阻止视频文件的直接访问并替换假blob链接
            if (window.fetch) {
                const originalFetch = window.fetch;
                window.fetch = function(url, options) {
                    let finalUrl = url;
                    
                    // 检查是否是假的blob URL
                    if (typeof finalUrl === 'string' && finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                        // 替换为真实URL
                        const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(finalUrl);
                        if (realUrl) {
                            finalUrl = realUrl;
                        } else {
                            // 如果找不到真实URL，拒绝访问
                            return Promise.reject(new Error('Access denied: fake blob URL not found'));
                        }
                    }
                    
                    // 检查是否是活跃的真实blob URL
                    if (typeof finalUrl === 'string' && finalUrl.startsWith('blob:') && !finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                        // 检查是否是活跃的blob URL
                        if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(finalUrl)) {
                            return Promise.reject(new Error('Access denied: blob URL not active'));
                        }
                        // 检查是否是从本页面发起的请求
                        const stack = new Error().stack;
                        if (!stack.includes('ModernChatVideoPlayer') && !stack.includes('createPlayModal') && !stack.includes('reloadVideo') && !stack.includes('playWithMSE') && !stack.includes('VideoProtection') && !stack.includes('fetchVideoSegments')) {
                            return Promise.reject(new Error('Access denied: only Modern Chat Video Player can access this blob URL'));
                        }
                    }
                    
                    // 增强网络请求保护，隐藏真实文件路径
                    if (typeof finalUrl === 'string' && (finalUrl.includes('.mp4') || finalUrl.includes('.mov') || finalUrl.includes('.mkv') || finalUrl.includes('.webm') || finalUrl.includes('.avi'))) {
                        // 这里可以添加更多的保护逻辑，比如使用加密的URL或代理
                    }
                    
                    return originalFetch.call(this, finalUrl, options);
                };
            }
            
            // 监控XMLHttpRequest请求
            if (window.XMLHttpRequest) {
                const originalOpen = XMLHttpRequest.prototype.open;
                XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
                    let finalUrl = url;
                    
                    // 检查是否是假的blob URL
                    if (typeof finalUrl === 'string' && finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                        // 替换为真实URL
                        const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(finalUrl);
                        if (realUrl) {
                            finalUrl = realUrl;
                        } else {
                            // 如果找不到真实URL，拒绝访问
                            throw new Error('Access denied: fake blob URL not found');
                        }
                    }
                    
                    // 检查是否是活跃的真实blob URL
                    if (typeof finalUrl === 'string' && finalUrl.startsWith('blob:') && !finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                        // 检查是否是活跃的blob URL
                        if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(finalUrl)) {
                            throw new Error('Access denied: blob URL not active');
                        }
                        // 检查是否是从本页面发起的请求
                        const stack = new Error().stack;
                        if (!stack.includes('ModernChatVideoPlayer') && !stack.includes('createPlayModal') && !stack.includes('reloadVideo') && !stack.includes('playWithMSE') && !stack.includes('VideoProtection') && !stack.includes('fetchVideoSegments')) {
                            throw new Error('Access denied: only Modern Chat Video Player can access this blob URL');
                        }
                    }
                    
                    // 增强网络请求保护，隐藏真实文件路径
                    if (typeof finalUrl === 'string' && (finalUrl.includes('.mp4') || finalUrl.includes('.mov') || finalUrl.includes('.mkv') || finalUrl.includes('.webm') || finalUrl.includes('.avi'))) {
                        // 这里可以添加更多的保护逻辑，比如使用加密的URL或代理
                    }
                    
                    return originalOpen.call(this, method, finalUrl, async, user, password);
                };
            }
            
            // 监控document.createElement('video')，防止创建新的视频元素访问blob URL
            const originalCreateElement = document.createElement;
            document.createElement = function(tagName) {
                const element = originalCreateElement.call(this, tagName);
                if (tagName.toLowerCase() === 'video') {
                        try {
                            const originalSrc = Object.getOwnPropertyDescriptor(HTMLVideoElement.prototype, 'src');
                            if (originalSrc && typeof originalSrc.set === 'function') {
                                Object.defineProperty(element, 'src', {
                                    get: function() {
                                        // 获取当前src值
                                        const currentSrc = originalSrc.get ? originalSrc.get.call(this) : '';
                                        // 如果是真实的blob URL，返回假的blob链接
                                        if (typeof currentSrc === 'string' && currentSrc.startsWith('blob:') && !currentSrc.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                                            // 查找对应的假blob链接
                                            for (const [fakeUrl, realUrl] of ModernChatVideoPlayer.fakeBlobMap.entries()) {
                                                if (realUrl === currentSrc) {
                                                    return fakeUrl;
                                                }
                                            }
                                            // 如果找不到对应的假blob链接，生成一个新的
                                            const fakeBlobUrl = ModernChatVideoPlayer.generateFakeBlobUrl(currentSrc);
                                            return fakeBlobUrl;
                                        }
                                        return currentSrc;
                                    },
                                    set: function(value) {
                                        let finalValue = value;
                                        // 检查是否是假的blob URL
                                        if (typeof finalValue === 'string' && finalValue.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                                            // 替换为真实URL
                                            const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(finalValue);
                                            if (realUrl) {
                                                finalValue = realUrl;
                                            } else {
                                                // 如果找不到真实URL，拒绝访问
                                                throw new Error('Access denied: fake blob URL not found');
                                            }
                                        } else if (typeof finalValue === 'string' && finalValue.startsWith('blob:') && !finalValue.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                                            // 检查是否是活跃的blob URL
                                            if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(finalValue)) {
                                                // 检查是否是MSE创建的blob URL
                                                const stack = new Error().stack;
                                                if (stack && (stack.includes('VideoProtection.playWithMSE') || stack.includes('playWithMSE'))) {
                                                    // 允许MSE播放时的blob URL访问
                                                } else {
                                                    throw new Error('Access denied: blob URL not active');
                                                }
                                            }
                                            // 检查是否是从本页面发起的请求
                                            const stack = new Error().stack;
                                            if (stack && !stack.includes('ModernChatVideoPlayer') && !stack.includes('createPlayModal') && !stack.includes('reloadVideo') && !stack.includes('VideoProtection.playWithMSE') && !stack.includes('playWithMSE') && !stack.includes('VideoProtection') && !stack.includes('fetchVideoSegments')) {
                                                throw new Error('Access denied: only Modern Chat Video Player can access this blob URL');
                                            }
                                        }
                                        originalSrc.set.call(this, finalValue);
                                    }
                                });
                            }
                        } catch (error) {
                            console.warn('监控视频元素时出错:', error);
                        }
                    }
                return element;
            };
            
            // 监控window.open，防止通过新窗口打开blob URL
            const originalOpen = window.open;
            window.open = function(url, name, features) {
                if (typeof url === 'string' && url.startsWith('blob:')) {
                    // 检查是否是假的blob URL
                    if (url.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                        throw new Error('Access denied: cannot open fake blob URL in new window');
                    }
                    // 检查是否是活跃的blob URL
                    if (ModernChatVideoPlayer.activeBlobUrls && !ModernChatVideoPlayer.activeBlobUrls.has(url)) {
                        throw new Error('Access denied: blob URL not active');
                    }
                    // 检查是否是从本页面发起的请求
                    const stack = new Error().stack;
                    if (!stack.includes('ModernChatVideoPlayer') && !stack.includes('createPlayModal')) {
                        throw new Error('Access denied: only Modern Chat Video Player can access this blob URL');
                    }
                }
                return originalOpen.call(this, url, name, features);
            };
        })();
        
        // 当视频播放器关闭时释放blob URL
        function releaseBlobUrl(blobUrl) {
            if (blobUrl && blobUrl.startsWith('blob:')) {
                // 检查是否是假的blob URL
                if (blobUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                    // 获取对应的真实blob URL
                    const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(blobUrl);
                    if (realUrl && realUrl.startsWith('blob:')) {
                        // 释放真实的blob URL
                        if (ModernChatVideoPlayer.activeBlobUrls) {
                            ModernChatVideoPlayer.activeBlobUrls.delete(realUrl);
                        }
                        if (ModernChatVideoPlayer.blobUrlMap) {
                            ModernChatVideoPlayer.blobUrlMap.delete(realUrl);
                        }
                        if (ModernChatVideoPlayer.blobUrlCreationTime) {
                            ModernChatVideoPlayer.blobUrlCreationTime.delete(realUrl);
                        }
                        if (ModernChatVideoPlayer.blobUrlContext) {
                            ModernChatVideoPlayer.blobUrlContext.delete(realUrl);
                        }
                        if (window.__deactivateBlobUrl) {
                            window.__deactivateBlobUrl(realUrl);
                        }
                        try {
                            URL.revokeObjectURL(realUrl);
                            console.log('已释放真实blob URL:', realUrl);
                        } catch (error) {
                            console.warn('释放真实blob URL失败:', error);
                        }
                    }
                    // 从假blob URL映射中移除
                    if (ModernChatVideoPlayer.fakeBlobMap) {
                        ModernChatVideoPlayer.fakeBlobMap.delete(blobUrl);
                    }
                    console.log('已释放假blob URL:', blobUrl);
                } else {
                    // 释放真实的blob URL
                    if (ModernChatVideoPlayer.activeBlobUrls) {
                        ModernChatVideoPlayer.activeBlobUrls.delete(blobUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlMap) {
                        ModernChatVideoPlayer.blobUrlMap.delete(blobUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlCreationTime) {
                        ModernChatVideoPlayer.blobUrlCreationTime.delete(blobUrl);
                    }
                    if (ModernChatVideoPlayer.blobUrlContext) {
                        ModernChatVideoPlayer.blobUrlContext.delete(blobUrl);
                    }
                    if (window.__deactivateBlobUrl) {
                        window.__deactivateBlobUrl(blobUrl);
                    }
                    try {
                        URL.revokeObjectURL(blobUrl);
                        console.log('已释放blob URL:', blobUrl);
                    } catch (error) {
                        console.warn('释放blob URL失败:', error);
                    }
                }
                
                // 关闭所有使用该blob URL的播放器
                document.querySelectorAll('video').forEach(function(video) {
                    if (video.src === blobUrl || video.src === ModernChatVideoPlayer.fakeBlobMap.get(blobUrl)) {
                        video.pause();
                        video.src = '';
                    }
                });
                
                // 关闭所有播放器弹窗
                const modals = document.querySelectorAll('.video-player-modal');
                modals.forEach(function(modal) {
                    modal.remove();
                });
                
                // 显示非法播放错误弹窗
                showIllegalPlayError();
            }
        }
        
        // 添加全局blob URL拦截器
        (function() {
            // 重写window.location的赋值操作
            const originalLocationAssign = window.location.assign;
            window.location.assign = function(url) {
                if (typeof url === 'string' && url.startsWith('blob:')) {
                    // 显示错误信息
                    document.body.innerHTML = `
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; background: #f0f0f0;">
                            <h1 style="color: #333;">未找到文件</h1>
                            <p style="color: #666;">它可能已被移动、编辑或删除。</p>
                        </div>
                    `;
                    return;
                }
                return originalLocationAssign.apply(this, arguments);
            };
            
            // 不重写window.location.href的赋值操作，因为这会导致错误
        // 改为监控popstate和hashchange事件来拦截导航
    })();
    
    // 全局视频元素监控，确保所有视频元素都有水印
    (function() {
        // 监控DOM中新增的视频元素
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.tagName === 'VIDEO') {
                        // 为新的视频元素添加水印
                        VideoProtection.addWatermark(node);
                    }
                });
            });
        });
        
        // 启动监控
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
        
        // 为现有的视频元素添加水印
        document.querySelectorAll('video').forEach(function(video) {
            VideoProtection.addWatermark(video);
        });
    })();
    
    // 开发者工具检测和防护
    (function() {
        // 标记开发者工具是否打开
        window.devToolsOpen = false;
        
        // 检测开发者工具是否打开
        function detectDevTools() {
            // 方法1：检测控制台对象（提高阈值，减少误判）
            const checkConsole = function() {
                // 检查页面是否可见，避免浏览器挂后台再切换回来时误判
                if (document.visibilityState !== 'visible') {
                    return;
                }
                
                const start = Date.now();
                const end = Date.now();
                
                if (end - start > 500 && !window.devToolsOpen) { // 提高阈值，减少误判
                    window.devToolsOpen = true;
                    onDevToolsOpen();
                } else if (end - start <= 500 && window.devToolsOpen) {
                    // 开发者工具已关闭
                    window.devToolsOpen = false;
                    onDevToolsClose();
                }
            };
            
            // 方法2：检测document元素大小变化（针对Elements面板）
            const element = new Image();
            Object.defineProperty(element, 'id', {
                get: function() {
                    // 暂时禁用此检测方法，避免误判
                    // if (!window.devToolsOpen) {
                    //     window.devToolsOpen = true;
                    //     onDevToolsOpen();
                    // }
                    return 'dev-tools-detected';
                }
            });
            
            // 方法3：使用requestAnimationFrame检测执行时间（提高阈值，减少误判）
            let rAFStop = false;
            const checkRAF = function() {
                // 检查页面是否可见，避免浏览器挂后台再切换回来时误判
                if (document.visibilityState !== 'visible') {
                    if (!rAFStop) {
                        requestAnimationFrame(checkRAF);
                    }
                    return;
                }
                
                const start = performance.now();
                requestAnimationFrame(function() {
                    const end = performance.now();
                    if (end - start > 500 && !window.devToolsOpen) { // 提高阈值，减少误判
                        window.devToolsOpen = true;
                        onDevToolsOpen();
                    } else if (end - start <= 500 && window.devToolsOpen) {
                        // 开发者工具已关闭
                        window.devToolsOpen = false;
                        onDevToolsClose();
                    }
                    if (!rAFStop) {
                        requestAnimationFrame(checkRAF);
                    }
                });
            };
            
            // 方法4：检测窗口的focus/blur事件（降低灵敏度，减少误判）
            let focusCount = 0;
            let lastFocusTime = 0;
            const checkWindowFocus = function() {
                // 检查页面是否可见，避免浏览器挂后台再切换回来时误判
                if (document.visibilityState !== 'visible') {
                    return;
                }
                
                const currentTime = Date.now();
                // 当两次focus事件在100ms内发生时，认为可能是开发者工具打开
                if (currentTime - lastFocusTime < 100) {
                    focusCount++;
                    if (focusCount > 10 && !window.devToolsOpen) { // 增加次数要求，减少误判
                        // 如果短时间内有多次focus事件，可能是开发者工具在新窗口打开
                        window.devToolsOpen = true;
                        onDevToolsOpen();
                    }
                } else {
                    focusCount = 1;
                }
                lastFocusTime = currentTime;
                // 重置计数器
                setTimeout(function() {
                    focusCount = 0;
                    lastFocusTime = 0;
                }, 5000); // 增加重置时间，减少误判
            };
            
            // 方法5：检测console对象的属性（提高阈值，减少误判）
            const checkConsoleProps = function() {
                // 检查页面是否可见，避免浏览器挂后台再切换回来时误判
                if (document.visibilityState !== 'visible') {
                    return;
                }
                
                try {
                    const prop = console;
                    let count = 0;
                    for (let key in prop) {
                        count++;
                    }
                    // 如果console对象的属性数量异常，可能是开发者工具打开
                    if (count > 100 && !window.devToolsOpen) { // 提高阈值，减少误判
                        window.devToolsOpen = true;
                        onDevToolsOpen();
                    }
                } catch (e) {
                    // 忽略错误
                }
            };
            
            // 方法6：检测窗口大小变化（提高阈值，减少误判）
            const checkWindowSize = function() {
                // 检查页面是否可见，避免浏览器挂后台再切换回来时误判
                if (document.visibilityState !== 'visible') {
                    return;
                }
                
                const widthThreshold = window.outerWidth - window.innerWidth > 300;
                const heightThreshold = window.outerHeight - window.innerHeight > 300;
                
                if ((widthThreshold || heightThreshold) && !window.devToolsOpen) {
                    window.devToolsOpen = true;
                    onDevToolsOpen();
                } else if (!(widthThreshold || heightThreshold) && window.devToolsOpen) {
                    // 开发者工具已关闭
                    window.devToolsOpen = false;
                    onDevToolsClose();
                }
            };
            
            // 方法7：检测document.visibilityState变化（已修改，避免误判）
            const checkVisibilityChange = function() {
                // 移除页面可见性变化时的开发者工具检测，避免浏览器挂后台后恢复前台时误判
                // if (document.visibilityState === 'visible' && !window.devToolsOpen) {
                //     // 当页面从隐藏变为可见时，检查开发者工具
                //     checkConsole();
                //     checkConsoleProps();
                // }
            };
            
            // 启动检测（降低检测频率，减少性能影响）
            setInterval(checkConsole, 5000); // 降低检测频率，设置为5秒
            setInterval(checkConsoleProps, 5000); // 降低检测频率，设置为5秒
            window.addEventListener('focus', checkWindowFocus);
            window.addEventListener('resize', checkWindowSize);
            document.addEventListener('visibilitychange', checkVisibilityChange);
            checkRAF();
            
            // 暂时禁用Elements面板检测，避免误判
            // setInterval(function() {
            //     // 不再直接输出element，而是通过访问其id属性来触发检测
            //     try {
            //         const id = element.id;
            //     } catch (e) {
            //         // 忽略错误
            //     }
            // }, 5000); // 减少检测频率
        }
        
        // 当开发者工具打开时
        function onDevToolsOpen() {
            // 检查是否禁止开发者工具
            if (!ModernChatVideoPlayerConfig.DeveloperToolsAreProhibited) {
                return;
            }
            
            console.warn('开发者工具被打开，已禁止视频播放');
            
            // 销毁所有blob链接
            destroyAllBlobUrls();
            
            // 禁止所有视频元素播放
            document.querySelectorAll('video').forEach(function(video) {
                if (!video.paused) {
                    video.pause();
                }
                video.src = '';
            });
            
            // 禁止所有播放按钮被点击
            disableAllPlayButtons();
        }
        
        // 当开发者工具关闭时
        function onDevToolsClose() {
            console.log('开发者工具已关闭，恢复视频播放功能');
            
            // 标记开发者工具已关闭
            window.devToolsOpen = false;
            
            // 启用所有播放按钮
            enableAllPlayButtons();
        }
        
        // 销毁所有blob链接
        function destroyAllBlobUrls() {
            // 确认开发者工具确实打开
            if (!window.devToolsOpen) {
                console.warn('开发者工具未打开，跳过blob链接销毁');
                return;
            }
            
            console.warn('正在销毁所有blob链接，防止视频被盗取');
            
            // 释放活跃的blob URL
            if (ModernChatVideoPlayer.activeBlobUrls) {
                ModernChatVideoPlayer.activeBlobUrls.forEach(function(blobUrl) {
                    try {
                        URL.revokeObjectURL(blobUrl);
                        console.log('已销毁blob URL:', blobUrl);
                    } catch (error) {
                        console.warn('销毁blob URL失败:', error);
                    }
                });
                ModernChatVideoPlayer.activeBlobUrls.clear();
            }
            
            // 清理其他blob URL相关集合
            if (ModernChatVideoPlayer.blobUrlMap) {
                ModernChatVideoPlayer.blobUrlMap.clear();
            }
            if (ModernChatVideoPlayer.blobUrlCreationTime) {
                ModernChatVideoPlayer.blobUrlCreationTime.clear();
            }
            if (ModernChatVideoPlayer.blobUrlContext) {
                ModernChatVideoPlayer.blobUrlContext.clear();
            }
            if (ModernChatVideoPlayer.fakeBlobMap) {
                ModernChatVideoPlayer.fakeBlobMap.clear();
            }
            
            // 额外清理：遍历所有可能的blob URL并销毁
            // 搜索所有可能的blob URL模式
            try {
                // 检查所有视频元素的src
                document.querySelectorAll('video').forEach(function(video) {
                    if (video.src && video.src.startsWith('blob:')) {
                        try {
                            URL.revokeObjectURL(video.src);
                            video.src = '';
                            console.log('已销毁视频元素的blob URL:', video.src);
                        } catch (error) {
                            console.warn('销毁视频元素blob URL失败:', error);
                        }
                    }
                });
            } catch (error) {
                console.warn('清理视频元素blob URL失败:', error);
            }
            
            // 清理所有可能的blob URL引用
            if (window.__blobUrls) {
                window.__blobUrls.forEach(function(blobUrl) {
                    try {
                        URL.revokeObjectURL(blobUrl);
                        console.log('已销毁全局blob URL:', blobUrl);
                    } catch (error) {
                        console.warn('销毁全局blob URL失败:', error);
                    }
                });
                window.__blobUrls = [];
            }
            
            console.warn('所有blob链接已销毁，视频内容得到保护');
            
            // 关闭所有播放器弹窗
            const modals = document.querySelectorAll('.video-player-modal');
            modals.forEach(function(modal) {
                modal.remove();
            });
            
            // 显示非法播放错误弹窗
            showIllegalPlayError();
        }
        
        // 显示非法播放错误弹窗
        function showIllegalPlayError() {
            // 检测系统主题
            const isDarkMode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            
            // 创建错误弹窗
            const errorModal = document.createElement('div');
            errorModal.style.position = 'fixed';
            errorModal.style.top = '0';
            errorModal.style.left = '0';
            errorModal.style.width = '100%';
            errorModal.style.height = '100%';
            errorModal.style.background = isDarkMode ? 'rgba(0, 0, 0, 0.9)' : 'rgba(0, 0, 0, 0.7)';
            errorModal.style.display = 'flex';
            errorModal.style.alignItems = 'center';
            errorModal.style.justifyContent = 'center';
            errorModal.style.zIndex = '99999';
            
            // 创建弹窗内容
            const content = document.createElement('div');
            content.style.background = isDarkMode ? '#2d2d2d' : 'white';
            content.style.color = isDarkMode ? 'white' : 'black';
            content.style.borderRadius = '12px';
            content.style.padding = '30px';
            content.style.width = '400px';
            content.style.textAlign = 'center';
            content.style.boxShadow = isDarkMode ? '0 4px 20px rgba(0, 0, 0, 0.5)' : '0 4px 20px rgba(0, 0, 0, 0.3)';
            content.style.border = isDarkMode ? '1px solid #444' : 'none';
            
            // 创建错误图标
            const errorIcon = document.createElement('div');
            errorIcon.style.fontSize = '48px';
            errorIcon.style.marginBottom = '20px';
            errorIcon.textContent = '❌';
            
            // 创建错误标题
            const errorTitle = document.createElement('h2');
            errorTitle.style.color = '#ff4d4f';
            errorTitle.style.marginBottom = '20px';
            errorTitle.textContent = '非法播放！！';
            
            // 创建倒计时文本
            const countdownText = document.createElement('p');
            countdownText.style.color = '#666';
            countdownText.style.marginBottom = '20px';
            countdownText.textContent = '页面将在 5 秒后刷新...';
            
            // 创建确定按钮
            const confirmBtn = document.createElement('button');
            confirmBtn.style.background = '#1890ff';
            confirmBtn.style.color = 'white';
            confirmBtn.style.border = 'none';
            confirmBtn.style.borderRadius = '6px';
            confirmBtn.style.padding = '10px 30px';
            confirmBtn.style.fontSize = '14px';
            confirmBtn.style.cursor = 'pointer';
            confirmBtn.style.transition = 'background 0.2s ease';
            confirmBtn.textContent = '确定';
            
            // 按钮点击事件
            confirmBtn.addEventListener('click', function() {
                errorModal.remove();
                // 强制刷新页面，确保所有资源重新加载
                location.reload(true);
            });
            
            // 组装弹窗
            content.appendChild(errorIcon);
            content.appendChild(errorTitle);
            content.appendChild(countdownText);
            content.appendChild(confirmBtn);
            errorModal.appendChild(content);
            
            // 添加到文档
            document.body.appendChild(errorModal);
            
            // 5秒后刷新页面
            let countdown = 5;
            const countdownInterval = setInterval(function() {
                countdown--;
                countdownText.textContent = `页面将在 ${countdown} 秒后刷新...`;
                if (countdown <= 0) {
                    clearInterval(countdownInterval);
                    errorModal.remove();
                    location.reload();
                }
            }, 1000);
        }
        
        // 禁止所有播放按钮被点击
        function disableAllPlayButtons() {
            console.warn('正在禁用所有播放视频的按钮');
            
            // 只禁用与视频播放相关的按钮
            document.querySelectorAll('.modern-chat-video-play-btn, .video-player-play-btn').forEach(function(btn) {
                // 检查按钮是否是视频播放按钮
                if (btn.className.includes('modern-chat-video-play-btn') || btn.className.includes('video-player-play-btn')) {
                    btn.disabled = true;
                    btn.style.cursor = 'not-allowed';
                    btn.style.opacity = '0.5';
                    // 移除现有的点击事件
                    const newBtn = btn.cloneNode(true);
                    newBtn.disabled = true;
                    newBtn.style.cursor = 'not-allowed';
                    newBtn.style.opacity = '0.5';
                    // 添加点击阻止
                    newBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.warn('开发者工具已打开，禁止播放视频');
                    });
                    btn.parentNode.replaceChild(newBtn, btn);
                }
            });
            
            // 只拦截与视频播放相关的点击操作
            document.addEventListener('click', function(e) {
                const target = e.target;
                // 只阻止视频播放按钮的点击
                if (target.className.includes('modern-chat-video-play-btn') || target.className.includes('video-player-play-btn')) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.warn('开发者工具已打开，禁止播放视频');
                }
            }, true);
        }
        
        // 启用所有播放按钮
        function enableAllPlayButtons() {
            console.log('正在启用所有播放视频的按钮');
            
            // 只启用与视频播放相关的按钮
            document.querySelectorAll('.modern-chat-video-play-btn, .video-player-play-btn').forEach(function(btn) {
                // 检查按钮是否是视频播放按钮
                if (btn.className.includes('modern-chat-video-play-btn') || btn.className.includes('video-player-play-btn')) {
                    btn.disabled = false;
                    btn.style.cursor = 'pointer';
                    btn.style.opacity = '1';
                }
            });
            
            // 移除全局点击事件拦截
            try {
                // 注意：由于我们使用了匿名函数，这里无法直接移除
                // 但在实际情况下，当开发者工具关闭时，新的点击事件会正常工作
                console.log('播放视频的按钮已启用');
            } catch (error) {
                console.warn('移除全局点击事件拦截失败:', error);
            }
        }
        
        // 禁止右键菜单
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
        });
        
        // 禁止F12键
        document.addEventListener('keydown', function(e) {
            // F12键
            if (e.key === 'F12') {
                e.preventDefault();
                e.stopPropagation();
            }
            // Ctrl+Shift+I, Ctrl+Shift+C, Ctrl+Shift+J
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'I' || e.key === 'C' || e.key === 'J')) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
        
        // 启动开发者工具检测
        detectDevTools();
    })();
    
    // 设置全局配置
    ModernChatVideoPlayer.setConfig = function(config) {
        Object.assign(ModernChatVideoPlayerConfig, config);
    };

    // 获取全局配置
    ModernChatVideoPlayer.getConfig = function(key) {
        if (key) {
            return ModernChatVideoPlayerConfig[key];
        }
        return ModernChatVideoPlayerConfig;
    };

    // 初始化必要的属性
    ModernChatVideoPlayer.activeBlobUrls = new Set();
    ModernChatVideoPlayer.blobUrlMap = new Map();
    ModernChatVideoPlayer.blobUrlCreationTime = new Map();
    ModernChatVideoPlayer.blobUrlContext = new Map();
    ModernChatVideoPlayer.fakeBlobMap = new Map();
    
    // 添加addWatermark方法到ModernChatVideoPlayer
    ModernChatVideoPlayer.addWatermark = function(videoElement) {
        // 检查视频元素是否已经有水印
        if (videoElement.querySelector('.video-watermark')) {
            return;
        }
        
        const watermark = document.createElement('div');
        watermark.className = 'video-watermark';
        watermark.style.position = 'absolute';
        watermark.style.top = '10px';
        watermark.style.left = '10px';
        watermark.style.color = 'rgba(255, 255, 255, 0.9)';
        watermark.style.fontSize = '18px';
        watermark.style.fontWeight = 'bold';
        watermark.style.pointerEvents = 'none';
        watermark.style.zIndex = '999999';
        watermark.style.textShadow = '2px 2px 4px rgba(0, 0, 0, 0.8)';
        watermark.style.userSelect = 'none';
        watermark.style.whiteSpace = 'nowrap';
        watermark.style.padding = '8px 12px';
        watermark.style.background = 'rgba(0, 0, 0, 0.6)';
        watermark.style.borderRadius = '6px';
        watermark.style.fontFamily = 'Arial, sans-serif';
        watermark.textContent = 'Modern Chat Video Player';
        
        // 确保视频元素有定位
        if (videoElement.style.position === '' || videoElement.style.position === 'static') {
            videoElement.style.position = 'relative';
        }
        
        // 确保视频元素的容器也有定位
        const container = videoElement.parentElement;
        if (container && (container.style.position === '' || container.style.position === 'static')) {
            container.style.position = 'relative';
        }
        
        // 将水印直接添加到视频元素中
        videoElement.appendChild(watermark);
        
        console.log('水印已添加到视频元素:', videoElement);
    };
    
    // 将VideoProtection设置为ModernChatVideoPlayer的引用
    Object.assign(VideoProtection, ModernChatVideoPlayer);

    async function createPlayModal(video, videoUrl) {
        try {
            // 创建弹窗容器
            const modal = document.createElement('div');
            modal.className = 'video-player-modal visible';
            modal.style.display = 'flex';
            modal.style.position = 'fixed';
            modal.style.top = '0';
            modal.style.left = '0';
            modal.style.width = '100%';
            modal.style.height = '100%';
            modal.style.background = 'rgba(0, 0, 0, 0.9)';
            modal.style.zIndex = '15000';
            modal.style.flexDirection = 'column';
            modal.style.alignItems = 'center';
            modal.style.justifyContent = 'center';
            
            // 创建弹窗内容
            const content = document.createElement('div');
            content.className = 'video-player-content';
            content.style.background = 'white';
            content.style.borderRadius = '12px';
            content.style.width = '90%';
            content.style.maxWidth = '1000px';
            content.style.maxHeight = '90vh';
            content.style.display = 'flex';
            content.style.flexDirection = 'column';
            content.style.overflow = 'hidden';
            
            // 创建弹窗头部
            const header = document.createElement('div');
            header.className = 'video-player-header';
            header.style.padding = '15px 20px';
            header.style.background = '#1976d2';
            header.style.color = 'white';
            header.style.display = 'flex';
            header.style.justifyContent = 'space-between';
            header.style.alignItems = 'center';
            
            const title = document.createElement('h3');
            title.className = 'video-player-title';
            title.style.margin = '0';
            title.style.fontSize = '16px';
            title.style.fontWeight = '600';
            // 使用视频元素的data-file-name属性作为文件名称，如果没有则使用原始逻辑
            const fileName = video.getAttribute('data-file-name') || videoUrl.split('/').pop().split('?')[0];
            title.textContent = fileName;
            
            const closeBtn = document.createElement('button');
            closeBtn.className = 'video-player-close';
            closeBtn.style.background = 'none';
            closeBtn.style.border = 'none';
            closeBtn.style.color = 'white';
            closeBtn.style.fontSize = '24px';
            closeBtn.style.cursor = 'pointer';
            closeBtn.style.padding = '0';
            closeBtn.style.width = '32px';
            closeBtn.style.height = '32px';
            closeBtn.style.display = 'flex';
            closeBtn.style.alignItems = 'center';
            closeBtn.style.justifyContent = 'center';
            closeBtn.style.borderRadius = '50%';
            closeBtn.style.transition = 'background 0.2s ease';
            closeBtn.innerHTML = '&times;';
            
            closeBtn.addEventListener('click', function() {
                modal.remove();
                if (video._player) {
                    // 检查视频是否已播放完成
                    const videoCompleted = video._player.videoCompleted;
                    video._player.destroy();
                    delete video._player;
                    // 只有当视频播放完成时，才释放blob URL
                    if (videoCompleted && videoUrl && videoUrl.startsWith('blob:')) {
                        releaseBlobUrl(videoUrl);
                    }
                } else {
                    // 如果没有播放器实例，检查视频元素状态
                    if (modalVideo && modalVideo.completed && videoUrl && videoUrl.startsWith('blob:')) {
                        releaseBlobUrl(videoUrl);
                    }
                }
                // 重置播放模态框标志，允许再次点击播放按钮
                video._player = null;
                video._playModalShown = false;
            });
            
            header.appendChild(title);
            header.appendChild(closeBtn);
            
            // 创建弹窗主体
            const body = document.createElement('div');
            body.className = 'video-player-body';
            body.style.flex = '1';
            body.style.display = 'flex';
            body.style.flexDirection = 'column';
            body.style.padding = '20px';
            body.style.background = '#000';
            
            // 创建新的视频元素用于弹窗播放
            const modalVideo = document.createElement('video');
            modalVideo.className = 'custom-video-element';
            modalVideo.style.flex = '1';
            modalVideo.style.width = '100%';
            modalVideo.style.height = '100%';
            modalVideo.style.objectFit = 'contain';
            modalVideo.style.background = '#000';
            modalVideo.setAttribute('data-modern-player', 'true');
            // 禁用浏览器自带的下载功能
            modalVideo.setAttribute('controlsList', 'nodownload noremoteplayback nofullscreen');
            modalVideo.setAttribute('disableRemotePlayback', 'true');
            modalVideo.setAttribute('preload', 'metadata');
            modalVideo.setAttribute('crossorigin', 'anonymous');
            
            // 添加视频保护事件
            modalVideo.addEventListener('contextmenu', function(e) {
                e.preventDefault();
            });
            
            modalVideo.addEventListener('keydown', function(e) {
                // 禁用键盘快捷键
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                    e.preventDefault();
                }
            });
            
            // 尝试使用MSE播放视频
            let useMSE = false;
            try {
                useMSE = await VideoProtection.playWithMSE(modalVideo, videoUrl);
            } catch (error) {
                console.error('Error in playWithMSE:', error);
                useMSE = false;
            }
            
            // 如果MSE播放失败，使用传统方式
            if (!useMSE) {
                // 检查videoUrl是否是假的blob URL
                let finalUrl = videoUrl;
                if (typeof finalUrl === 'string' && finalUrl.startsWith(`blob:https://${window.location.hostname}/fake-`)) {
                    // 替换为真实URL
                    const realUrl = ModernChatVideoPlayer.fakeBlobMap.get(finalUrl);
                    if (realUrl) {
                        finalUrl = realUrl;
                    }
                }
                console.log('Falling back to traditional playback for:', finalUrl);
                modalVideo.src = finalUrl;
            }
            
            // 等待视频元素加载完成后再初始化播放器
            modalVideo.addEventListener('loadedmetadata', function() {
                // 初始化弹窗中的播放器，传递原始视频元素引用
                video._player = new ModernChatVideoPlayerClass(modalVideo, video);
            });
            
            // 处理视频加载错误
            modalVideo.addEventListener('error', function() {
                console.error('视频加载失败:', this.error);
                // 即使视频加载失败，也初始化播放器，传递原始视频元素引用
                video._player = new ModernChatVideoPlayerClass(modalVideo, video);
            });
            
            body.appendChild(modalVideo);
            
            content.appendChild(header);
            content.appendChild(body);
            modal.appendChild(content);
            
            document.body.appendChild(modal);
            
            // 确保视频元素被正确添加到DOM后再加载
            setTimeout(() => {
                if (!video._player) {
                    // 如果视频元素还没有加载完成，强制初始化播放器，传递原始视频元素引用
                    video._player = new ModernChatVideoPlayerClass(modalVideo, video);
                }
            }, 500);
        } catch (error) {
            console.error('显示播放弹窗失败:', error);
            // 重置播放模态框标志，允许再次点击播放按钮
            video._playModalShown = false;
        }
    }
};

/**
 * 页面加载完成后初始化播放器
 */
function initializeVideoPlayers() {
    // 确保DOM已经完全加载
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            // 等待所有资源（包括CSS和JS）加载完成
            window.addEventListener('load', function() {
                ModernChatVideoPlayer.initAll();
                // 为所有播放按钮覆盖层添加点击事件
                ModernChatVideoPlayer.bindPlayButtonEvents();
            });
        });
    } else if (document.readyState === 'interactive') {
        // 等待所有资源（包括CSS和JS）加载完成
        window.addEventListener('load', function() {
            ModernChatVideoPlayer.initAll();
            // 为所有播放按钮覆盖层添加点击事件
            ModernChatVideoPlayer.bindPlayButtonEvents();
        });
    } else {
        // 已经完全加载，直接初始化
        ModernChatVideoPlayer.initAll();
        // 为所有播放按钮覆盖层添加点击事件
        ModernChatVideoPlayer.bindPlayButtonEvents();
    }
}

// 初始化播放器
initializeVideoPlayers();
