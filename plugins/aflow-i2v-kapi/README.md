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

After installation, configure your KIE.AI API key:

1. Go to Settings → Integrations
2. Enter your KIE.AI API key
3. The plugin will automatically use this key

Alternatively, you can enter the API key directly in the node field.

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

| Field | Description |
|-------|-------------|
| API Key | Your KIE.AI API key (optional if configured in Settings) |
| Aspect Ratio | Video orientation: Landscape (horizontal) or Portrait (vertical) |
| Motion Prompt | Detailed description of the animation and motion (up to 10000 characters) |
| Duration | Video length in seconds (10 or 15) |

## API Reference

This plugin uses the KIE.AI Sora 2 Image-to-Video API:
- Create Task: `POST https://api.kie.ai/api/v1/jobs/createTask`
- Query Status: `GET https://api.kie.ai/api/v1/jobs/recordInfo?taskId=xxx`

## Model Details

- **Model**: `sora-2-image-to-video`
- **Max Image Size**: 10MB
- **Supported Formats**: JPEG, PNG, WebP
- **Watermark Removal**: Enabled by default

## Support

For issues or questions, please contact support@aikaflow.com

## License

MIT License
