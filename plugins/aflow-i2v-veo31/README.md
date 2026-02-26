# Image to Video Veo3.1 Plugin

A custom AIKAFLOW plugin for generating videos from images using RunningHub's Veo3.1 API.

## Features

- Generate 8 second videos from static images
- Support for landscape (16:9) and portrait (9:16) aspect ratios
- Resolution options: 720p, 1080p, 4K
- Up to 3 input images (max 10MB each)
- Motion prompts (5-800 characters)
- Uses Veo3.1 model for high-quality video generation

## Installation

1. Download this plugin as a ZIP file
2. Go to AIKAFLOW Editor → Settings → Plugins
3. Click "Upload Plugin" and select the ZIP file
4. Enable the plugin

## Configuration

Configure your RunningHub API key in **Administration → Integrations**:

1. Login as Admin
2. Go to Administration → Integrations tab
3. Find "Generation API" section (rhub provider)
4. Enter your RunningHub API key
5. Save

To get your API key, visit: https://www.runninghub.ai

## Usage

1. Drag the "Image to Video Veo3.1" node from the sidebar onto the canvas
2. Connect image input(s) to the node (up to 3 images)
3. Configure the settings:
   - Select the aspect ratio (Portrait 9:16 or Landscape 16:9)
   - Select the resolution (720p, 1080p, or 4K)
   - Write a motion prompt describing the animation (5-800 characters)
4. Run the workflow

## Node Fields

| Field         | Description                                                |
| ------------- | ---------------------------------------------------------- |
| Aspect Ratio  | Video orientation: `9:16` (portrait) or `16:9` (landscape) |
| Resolution    | Output quality: `720p`, `1080p`, or `4k`                   |
| Motion Prompt | Description of the animation (5-800 characters)            |
| Duration      | Video length: 8 seconds                                    |

## Node Inputs

| Input         | Type  | Required | Description                 |
| ------------- | ----- | -------- | --------------------------- |
| Input Image 1 | image | Yes      | Primary image for the video |
| Input Image 2 | image | No       | Optional second image       |
| Input Image 3 | image | No       | Optional third image        |
| Motion Prompt | text  | No       | Overrides the prompt field  |

## API Reference

This plugin uses the RunningHub Veo3.1 Image-to-Video API.

### Create Task

- **URL**: `POST https://www.runninghub.ai/openapi/v2/rhart-video-v3.1-fast/image-to-video`
- **Authorization**: Bearer token

### Request Parameters

| Parameter   | Type         | Required | Description                            |
| ----------- | ------------ | -------- | -------------------------------------- |
| prompt      | String       | Yes      | Motion description (5-800 chars)       |
| aspectRatio | String       | Yes      | `16:9` or `9:16`                       |
| imageUrls   | List(String) | Yes      | Array of image URLs (max 3, 10MB each) |
| duration    | String       | No       | Video duration: `8`                    |
| resolution  | String       | Yes      | `720p`, `1080p`, or `4k`               |
| webhookUrl  | String       | No       | Callback URL for task completion       |

### Request Example

```json
{
  "prompt": "A woman gracefully modeling a flowing hijab...",
  "aspectRatio": "9:16",
  "imageUrls": [
    "https://example.com/image1.jpg",
    "https://example.com/image2.jpg"
  ],
  "duration": "8",
  "resolution": "720p"
}
```

### Query Status

- **URL**: `POST https://www.runninghub.ai/openapi/v2/query`
- **Body**: `{ "taskId": "xxx" }`

### Response Fields

| Field        | Type   | Description                                          |
| ------------ | ------ | ---------------------------------------------------- |
| taskId       | String | Task ID, used to query status                        |
| status       | String | QUEUED, RUNNING, SUCCESS, FAILED                     |
| errorCode    | String | Error code (on failure)                              |
| errorMessage | String | Detailed error message                               |
| results      | List   | List of results (url, outputType, text)              |
| usage        | Object | Cost info (consumeMoney, consumeCoins, taskCostTime) |

### File Upload

`imageUrls` accepts:

- **Public URL**: `https://example.com/image.png`
- **Base64 data URI**: `data:image/png;base64,iVBORw0KGgo...`
- **RH Upload API**: `POST https://www.runninghub.cn/openapi/v2/media/upload/binary` (URL valid for 1 day)

## Support

For issues or questions, please contact support@aikaflow.com

## License

MIT License
