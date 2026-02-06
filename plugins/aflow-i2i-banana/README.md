# Nano Banana Image to Image Plugin

A custom AIKAFLOW plugin for AI-powered image transformation using RunningHub's Nano Banana model.

## Features

- Transform images with AI-powered editing
- Prompt-based image modification (5-4000 characters)
- Multiple resolution options (1K, 2K, 4K)
- High-quality output images

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

1. Drag the "Nano Banana I2I" node from the sidebar onto the canvas
2. Connect an image input to the node
3. Configure the settings:
   - Write a prompt describing how to transform the image
   - Select the output resolution (1K, 2K, or 4K)
4. Run the workflow

## Node Fields

| Field       | Description                                                   |
| ----------- | ------------------------------------------------------------- |
| Edit Prompt | Description of how to transform the image (5-4000 characters) |
| Resolution  | Output quality: `1k`, `2k`, or `4k`                           |

## API Reference

This plugin uses the RunningHub Nano Banana Image-to-Image API.

### Create Task

- **URL**: `POST https://www.runninghub.ai/openapi/v2/rhart-image-n-pro/edit`
- **Authorization**: Bearer token

### Request Parameters

| Parameter   | Type   | Required | Description                                     |
| ----------- | ------ | -------- | ----------------------------------------------- |
| imageUrls   | array  | Yes      | Array of image URLs (max 10, 10MB each)         |
| prompt      | string | Yes      | Image transformation description (5-4000 chars) |
| resolution  | string | Yes      | Output resolution: `1k`, `2k`, `4k`             |
| aspectRatio | string | No       | Output ratio (1:1, 16:9, 9:16, etc.)            |

### Request Example

```json
{
  "imageUrls": ["https://example.com/image.jpg"],
  "prompt": "Transform the scene to a watercolor painting style with warm tones",
  "resolution": "2k"
}
```

### Query Status

- **URL**: `POST https://www.runninghub.ai/openapi/v2/query`
- **Body**: `{ "taskId": "xxx" }`

## Support

For issues or questions, please contact support@aikaflow.com

## License

MIT License
