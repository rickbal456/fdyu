# Image to Video KAPI Plugin

A custom AIKAFLOW plugin for generating videos from images using KIE.AI's Sora 2 Image-to-Video API.

## Features

- Generate 10-15 second videos from static images
- Support for landscape (horizontal) and portrait (vertical) aspect ratios
- Detailed motion prompts up to 10000 characters
- Uses KIE.AI Sora 2 model for high-quality video generation
- Watermark-free output

## Installation

1. Download this plugin as a ZIP file
2. Go to AIKAFLOW Editor → Settings → Plugins
3. Click "Upload Plugin" and select the ZIP file
4. Enable the plugin

## Configuration

After installation, configure your KIE.AI API key in **Administration → Integrations**:

1. Login as Admin
2. Go to Administration → Integrations tab
3. Find "Video Generation API" section
4. Enter your KIE.AI API key
5. Save

To get your API key, visit: https://kie.ai/api-key

## Usage

1. Drag the "Image to Video KAPI" node from the sidebar onto the canvas
2. Connect an image input to the node
3. Configure the settings:
   - Select the aspect ratio (Landscape or Portrait)
   - Write a detailed motion prompt describing the animation
   - Set the duration (10 or 15 seconds)
4. Run the workflow

## Node Fields

| Field         | Description                                                               |
| ------------- | ------------------------------------------------------------------------- |
| Aspect Ratio  | Video orientation: `landscape` (16:9) or `portrait` (9:16)                |
| Motion Prompt | Detailed description of the animation and motion (up to 10000 characters) |
| Duration      | Video length in seconds (10 or 15)                                        |

## API Reference

This plugin uses the KIE.AI Sora 2 Image-to-Video API.

### Create Task

- **URL**: `POST https://api.kie.ai/api/v1/jobs/createTask`
- **Content-Type**: `application/json`

### Request Parameters

| Parameter              | Type    | Required | Description                                    |
| ---------------------- | ------- | -------- | ---------------------------------------------- |
| model                  | string  | Yes      | `sora-2-image-to-video`                        |
| input.prompt           | string  | Yes      | Motion/animation description (max 10000 chars) |
| input.image_urls       | array   | Yes      | Array of image URLs (1 image)                  |
| input.aspect_ratio     | string  | No       | `portrait` or `landscape` (default: landscape) |
| input.n_frames         | string  | No       | `10` or `15` seconds                           |
| input.remove_watermark | boolean | No       | Remove watermark (default: true)               |

### Request Example

```json
{
  "model": "sora-2-image-to-video",
  "input": {
    "prompt": "A woman gracefully modeling a flowing hijab, turning slowly...",
    "image_urls": ["https://example.com/image.jpg"],
    "aspect_ratio": "portrait",
    "n_frames": "10",
    "remove_watermark": true
  }
}
```

### Query Status

- **URL**: `GET https://api.kie.ai/api/v1/jobs/recordInfo?taskId=xxx`

## Model Details

- **Model**: `sora-2-image-to-video`
- **Max Image Size**: 10MB
- **Supported Formats**: JPEG, PNG, WebP
- **Watermark Removal**: Enabled by default

## Troubleshooting

### Portrait generates Landscape

Make sure you select "Portrait (Vertical)" in the Aspect Ratio dropdown, not "Landscape".

### API Key Error

Ensure your KIE.AI API key is configured in Administration → Integrations.

## Support

For issues or questions, please contact support@aikaflow.com

## License

MIT License
