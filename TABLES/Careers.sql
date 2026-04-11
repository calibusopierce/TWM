USE [TradewellDatabase]
GO

/****** Object:  Table [dbo].[Careers]    Script Date: 4/10/2026 2:58:21 PM ******/
SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE TABLE [dbo].[Careers](
	[JobTitle] [nvarchar](50) NULL,
	[JobDescription] [nvarchar](max) NULL,
	[Department] [nvarchar](50) NULL,
	[Qualifications] [nvarchar](max) NULL,
	[Location] [nvarchar](50) NULL,
	[IsActive] [bit] NULL,
	[CareerID] [int] IDENTITY(1,1) NOT NULL,
	[JobImage] [varbinary](max) NULL,
PRIMARY KEY CLUSTERED 
(
	[CareerID] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO


