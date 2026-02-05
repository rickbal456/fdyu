# Image to Video Veo3.1 Plugin

A custom AIKAFLOW plugin for generating videos from images using RunningHub's Veo3.1 API.

## Features

- Generate 8 second videos from static images
- Support for landscape (16:9) and portrait (9:16) aspect ratios
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
2. Connect an image input to the node
3. Configure the settings:
   - Select the aspect ratio (Portrait 9:16 or Landscape 16:9)
   - Write a motion prompt describing the animation (5-800 characters)
4. Run the workflow

## Node Fields

| Field         | Description                                                |
| ------------- | ---------------------------------------------------------- |
| Aspect Ratio  | Video orientation: `9:16` (portrait) or `16:9` (landscape) |
| Motion Prompt | Description of the animation (5-800 characters)            |
| Duration      | Video length: 8 seconds                                    |

## API Reference

This plugin uses the RunningHub Veo3.1 Image-to-Video API.

### Create Task

- **URL**: `POST https://www.runninghub.ai/openapi/v2/rhart-video-v3.1-fast/image-to-video`
- **Authorization**: Bearer token

### Request Parameters

| Parameter   | Type   | Required | Description                            |
| ----------- | ------ | -------- | -------------------------------------- |
| prompt      | string | Yes      | Motion description (5-800 chars)       |
| aspectRatio | string | Yes      | `16:9` or `9:16`                       |
| imageUrls   | array  | Yes      | Array of image URLs (max 3, 10MB each) |
| duration    | string | No       | Video duration: `8`                    |

### Request Example

```json
{
  "prompt": "A woman gracefully modeling a flowing hijab...",
  "aspectRatio": "9:16",
  "imageUrls": ["https://example.com/image.jpg"],
  "duration": "8"
}
```

### Query Status

- **URL**: `POST https://www.runninghub.ai/openapi/v2/query`
- **Body**: `{ "taskId": "xxx" }`

## Support

For issues or questions, please contact support@aikaflow.com

## License

MIT License
